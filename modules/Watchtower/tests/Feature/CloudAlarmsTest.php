<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Platform\Support\SentryDsn;
use Wurk\Watchtower\Jobs\SendToSentry;
use Wurk\Watchtower\Mail\IncidentMail;
use Wurk\Watchtower\Models\Incident;
use Wurk\Watchtower\Support\Alerts;
use Wurk\Watchtower\Support\SentryForwarder;
use Wurk\Watchtower\Support\Signal;

/*
 * Sprint 17.5: the cloud's alarms arrive from Amazon SNS, checked as SNS signs them, and become Watchtower problems —
 * emailed to the people in Admin → Watchtower's settings; an alarm back to OK resolves it.
 */

beforeEach(function () {
    Mail::fake();
    config(['wurk.watchtower.recipients' => ['oncall@example.test'], 'wurk.watchtower.ai' => false,
        'wurk.watchtower.alarm_token' => 'alarm-token-0123456789', 'wurk.watchtower.alarm_topic' => 'arn:aws:sns:af-south-1:123456789012:wurk-production-alarms']);
    $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $this->key);
    openssl_x509_export(openssl_csr_sign($csr, null, $this->key, 1), $pem);
    $this->cert = 'https://sns.af-south-1.amazonaws.com/SimpleNotificationService-abc.pem';
    Http::fake([$this->cert => Http::response($pem), 'sns.af-south-1.amazonaws.com/?Action=ConfirmSubscription*' => Http::response('<ok/>')]);
    $this->sns = function (string $type, array $m, ?string $topic = null): array {
        $m = ['Type' => $type, 'MessageId' => 'm-'.uniqid(), 'TopicArn' => $topic ?? 'arn:aws:sns:af-south-1:123456789012:wurk-production-alarms',
            'Timestamp' => '2027-03-01T09:00:00.000Z', 'SignatureVersion' => '2', 'SigningCertURL' => $this->cert, ...$m];
        $fields = $type === 'Notification' ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'] : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $text = '';
        foreach ($fields as $f) {
            if (isset($m[$f])) {
                $text .= $f."\n".$m[$f]."\n";
            }
        }
        openssl_sign($text, $sig, $this->key, OPENSSL_ALGO_SHA256);

        return [...$m, 'Signature' => base64_encode($sig)];
    };
    $this->post = fn (array $m, string $token = 'alarm-token-0123456789') => $this->call('POST', '/_wurk/alarms/'.$token, [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($m));
    $this->alarm = fn (string $state) => json_encode(['AlarmName' => 'wurk-production-5xx', 'AlarmDescription' => 'More than 2% of requests fail at the load balancer',
        'NewStateValue' => $state, 'NewStateReason' => 'Threshold Crossed: 1 datapoint [4.2] was greater than the threshold (2.0).', 'Region' => 'Africa (Cape Town)']);
});

it('confirms WURK’s subscription to the alarm topic, only from SNS', function () {
    ($this->post)(($this->sns)('SubscriptionConfirmation', ['Message' => 'You have chosen to subscribe', 'Token' => 't1',
        'SubscribeURL' => 'https://sns.af-south-1.amazonaws.com/?Action=ConfirmSubscription&Token=t1']))->assertNoContent();
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://sns.af-south-1.amazonaws.com/?Action=ConfirmSubscription'));
});

it('turns an alarm into a problem HQ is emailed about, and resolves it when the alarm clears', function () {
    ($this->post)(($this->sns)('Notification', ['Subject' => 'ALARM: "wurk-production-5xx"', 'Message' => ($this->alarm)('ALARM')]))->assertOk()->assertSeeText('raised');
    $i = Incident::query()->where('kind', 'cloud')->sole();
    expect($i->only(['severity', 'ongoing']))->toBe(['severity' => 'critical', 'ongoing' => true])
        ->and($i->title)->toBe('Cloud alarm: More than 2% of requests fail at the load balancer');
    app(Alerts::class)->sendDue();
    Mail::assertSent(IncidentMail::class, fn (IncidentMail $m) => $m->hasTo('oncall@example.test'));

    ($this->post)(($this->sns)('Notification', ['Subject' => 'OK: "wurk-production-5xx"', 'Message' => ($this->alarm)('OK')]))->assertSeeText('resolved');
    expect($i->fresh()->only(['ongoing', 'status']))->toBe(['ongoing' => false, 'status' => 'resolved']);
});

it('refuses anything that isn’t signed by SNS for WURK’s topic', function () {
    $good = ($this->sns)('Notification', ['Subject' => 'ALARM', 'Message' => ($this->alarm)('ALARM')]);
    ($this->post)($good, 'wrong-token-0123456789')->assertNotFound();
    ($this->post)([...$good, 'Message' => ($this->alarm)('OK')])->assertForbidden(); // tampered
    ($this->post)(($this->sns)('Notification', ['Subject' => 'ALARM', 'Message' => ($this->alarm)('ALARM')], 'arn:aws:sns:af-south-1:999:someone-else'))->assertForbidden();
    ($this->post)([...$good, 'SigningCertURL' => 'https://evil.example.com/cert.pem'])->assertForbidden();
    config(['wurk.watchtower.alarm_token' => null]);
    ($this->post)($good)->assertNotFound();
    expect(Incident::query()->where('kind', 'cloud')->count())->toBe(0);
});

it('also sends server errors to Sentry when it’s switched on — masked, once a minute per problem (decision G)', function () {
    app(ProviderSettings::class)->save('errors.sentry', 'sentry', ['dsn' => 'https://abc123@o42.ingest.sentry.io/4507'], ['environment' => 'staging'], true, null);
    Queue::fake();
    $forward = fn (string $message) => app(SentryForwarder::class)->forward(
        Signal::fromThrowable(new RuntimeException($message), 'exception', 'GET /glow-salon/bookings', 'app'));
    $forward('Card 4111111111111111 declined for thandi@example.com');
    $forward('Card 4111111111111111 declined for thandi@example.com'); // same problem within the minute
    Queue::assertPushed(SendToSentry::class, 1);
    Queue::assertPushed(SendToSentry::class, function ($job) {
        $value = $job->event['exception']['values'][0]['value'];

        return $job->event['environment'] === 'staging' && $job->event['exception']['values'][0]['type'] === 'RuntimeException'
            && ! str_contains($value, '4111111111111111') && ! str_contains($value, 'thandi@example.com');
    });

    Http::fake(['o42.ingest.sentry.io/*' => Http::response(['id' => 'x'])]);
    (new SendToSentry(['event_id' => 'e1', 'message' => 'x']))->handle(app(ProviderCredentials::class));
    Http::assertSent(fn ($r) => $r->url() === 'https://o42.ingest.sentry.io/api/4507/envelope/' && str_contains($r->header('X-Sentry-Auth')[0], 'sentry_key=abc123'));
    expect(SentryDsn::parse('http://abc@o1.ingest.sentry.io/1'))->toBeNull()->and(SentryDsn::parse('nonsense'))->toBeNull();
});
