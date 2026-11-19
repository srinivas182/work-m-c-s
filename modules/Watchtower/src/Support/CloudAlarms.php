<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sprint 17.5: the cloud's alarms (CloudWatch → SNS) arrive here and become Watchtower problems, so they reach the
 * people in Admin → Watchtower's settings — never addresses written into code. An alarm going to OK resolves it.
 *
 * Every message is checked as SNS signs it (the certificate must come from sns.<region>.amazonaws.com), and must
 * be from WURK's own topic.
 */
final readonly class CloudAlarms
{
    public function __construct(private Recorder $recorder, private Alerts $alerts) {}

    /** @param array<string, mixed> $m the SNS message */
    public function verified(array $m): bool
    {
        $topic = (string) config('wurk.watchtower.alarm_topic', '');
        if ($topic === '' || ($m['TopicArn'] ?? null) !== $topic) {
            return false;
        }
        $cert = (string) ($m['SigningCertURL'] ?? '');
        $parts = parse_url($cert);
        if (($parts['scheme'] ?? '') !== 'https' || preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', (string) ($parts['host'] ?? '')) !== 1 || ! str_ends_with((string) ($parts['path'] ?? ''), '.pem')) {
            return false;
        }
        $fields = match ($m['Type'] ?? null) {
            'Notification' => isset($m['Subject']) ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'] : ['Message', 'MessageId', 'Timestamp', 'TopicArn', 'Type'],
            'SubscriptionConfirmation', 'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
            default => null,
        };
        if ($fields === null) {
            return false;
        }
        $text = '';
        foreach ($fields as $f) {
            if (! is_string($m[$f] ?? null)) {
                return false;
            }
            $text .= $f."\n".$m[$f]."\n";
        }
        try {
            $pem = Cache::remember('watchtower:sns-cert:'.sha1($cert), 86400, fn () => Http::timeout(10)->get($cert)->throw()->body());
            $signature = base64_decode((string) ($m['Signature'] ?? ''), true);

            return $signature !== false && openssl_verify($text, $signature, $pem, ($m['SignatureVersion'] ?? '1') === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /** Subscribing WURK to the topic: confirmed by visiting the address SNS gave (only on SNS's own host). */
    public function confirm(string $subscribeUrl): bool
    {
        $host = (string) parse_url($subscribeUrl, PHP_URL_HOST);
        if (preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host) !== 1) {
            return false;
        }

        return Http::timeout(10)->get($subscribeUrl)->successful();
    }

    /** A CloudWatch alarm changed state. Returns what Watchtower did: raised, resolved or ignored. */
    public function changed(string $message): string
    {
        $a = json_decode($message, true);
        if (! is_array($a) || ! is_string($a['AlarmName'] ?? null)) {
            return 'ignored';
        }
        $name = mb_substr($a['AlarmName'], 0, 120);
        $state = (string) ($a['NewStateValue'] ?? '');
        if ($state === 'OK') {
            $incident = $this->recorder->clear('cloud', $name);
            if ($incident !== null) {
                $this->alerts->recovered($incident);
            }

            return 'resolved';
        }
        if ($state !== 'ALARM') {
            return 'ignored'; // INSUFFICIENT_DATA: not a problem by itself
        }
        $why = mb_substr((string) ($a['NewStateReason'] ?? ''), 0, 500);
        $this->recorder->record(new Signal('cloud', $name, 'Cloud alarm: '.(is_string($a['AlarmDescription'] ?? null) && $a['AlarmDescription'] !== '' ? mb_substr($a['AlarmDescription'], 0, 160) : $name),
            severity: str_contains($name, '-warn-') ? 'high' : 'critical', message: $why, location: 'CloudWatch alarm '.$name, surface: 'system', ongoing: true,
            context: ['alarm' => $name, 'region' => $a['Region'] ?? null, 'since' => $a['StateChangeTime'] ?? null]));

        return 'raised';
    }
}
