<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Wurk\Messaging\Drivers\Sms\BrevoSms;
use Wurk\Messaging\Drivers\Sms\BulkSms;
use Wurk\Messaging\Drivers\Sms\ClickatellSms;
use Wurk\Messaging\Drivers\Sms\SmsPortalSms;
use Wurk\Messaging\Drivers\Sms\TwilioSms;
use Wurk\Messaging\Drivers\WhatsApp\MetaCloudWhatsApp;

/*
| Each driver's request is pinned against the provider's published API:
| endpoint, authentication and body. Responses are representative samples.
*/

it('sends through SMSPortal v3 with Basic auth and test mode', function () {
    Http::fake(['rest.smsportal.com/*' => Http::response(['eventId' => 123456, 'messages' => 1], 200)]);

    $result = (new SmsPortalSms)->send('+27821234567', 'Hi', ['client_id' => 'cid', 'api_secret' => 'sec'], ['test_mode' => '1']);

    expect($result->ok)->toBeTrue()->and($result->providerId)->toBe('123456');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://rest.smsportal.com/v3/bulkmessages'
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('cid:sec'))
        && $r['messages'][0] === ['content' => 'Hi', 'destination' => '27821234567']
        && $r['sendOptions']['testMode'] === true);
});

it('sends through Clickatell One API with the key in Authorization', function () {
    Http::fake(['platform.clickatell.com/*' => Http::response(['messages' => [['apiMessageId' => 'abc', 'accepted' => true, 'to' => '27821234567']]], 202)]);

    expect((new ClickatellSms)->send('+27821234567', 'Hi', ['api_key' => 'key123'], [])->providerId)->toBe('abc');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://platform.clickatell.com/messages' && $r->hasHeader('Authorization', 'key123')
        && $r['messages'][0] === ['channel' => 'sms', 'to' => '27821234567', 'content' => 'Hi']);
});

it('sends through BulkSMS with Basic auth', function () {
    Http::fake(['api.bulksms.com/*' => Http::response([['id' => '9001', 'type' => 'SENT']], 201)]);

    expect((new BulkSms)->send('+27821234567', 'Hi', ['token_id' => 't', 'token_secret' => 's'], [])->providerId)->toBe('9001');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.bulksms.com/v1/messages' && $r['to'] === '+27821234567' && $r['body'] === 'Hi');
});

it('sends through Brevo’s current endpoint (not the deprecated /sms one)', function () {
    Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 1511882900176220], 201)]);

    expect((new BrevoSms)->send('+27821234567', 'Hi', ['api_key' => 'xkeysib'], ['sender' => 'WURK Co!'])->providerId)->toBe('1511882900176220');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.brevo.com/v3/transactionalSMS/send' && $r->hasHeader('api-key', 'xkeysib')
        && $r['recipient'] === '27821234567' && $r['sender'] === 'WURKCo' && $r['type'] === 'transactional');
});

it('sends through Twilio as a form with a Messaging Service when set', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

    expect((new TwilioSms)->send('+27821234567', 'Hi', ['account_sid' => 'AC1', 'auth_token' => 'tok'], ['messaging_service_sid' => 'MG9'])->providerId)->toBe('SM123');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC1/Messages.json'
        && $r->isForm() && $r['MessagingServiceSid'] === 'MG9' && ! isset($r['From']));
});

it('sends WhatsApp authentication templates with the code on the copy button', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

    $result = (new MetaCloudWhatsApp)->sendTemplate('+27821234567', 'wurk_sign_in', ['123456'], 'en', ['access_token' => 'EAAG'], ['phone_number_id' => '555'], authentication: true);

    expect($result->providerId)->toBe('wamid.1');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/'.MetaCloudWhatsApp::DEFAULT_VERSION.'/555/messages'
        && $r->hasHeader('Authorization', 'Bearer EAAG')
        && $r['template']['components'][0]['parameters'][0]['text'] === '123456'
        && $r['template']['components'][1]['sub_type'] === 'url');
});

it('turns provider errors and outages into failed results instead of exceptions', function (string $driver, array $fake) {
    Http::fake($fake);
    $result = (new $driver)->send('+27821234567', 'Hi', ['api_key' => 'k', 'client_id' => 'c', 'api_secret' => 's', 'token_id' => 't', 'token_secret' => 's', 'account_sid' => 'a', 'auth_token' => 't'], []);

    expect($result->ok)->toBeFalse()->and($result->error)->not->toBeEmpty();
})->with([
    // Closures: Pest resolves them inside the test, after the app has booted.
    'SMSPortal 401' => [SmsPortalSms::class, fn () => ['*' => Http::response(['errorReport' => ['faults' => [['errorMessage' => 'Invalid credentials']]]], 401)]],
    'Brevo 400' => [BrevoSms::class, fn () => ['*' => Http::response(['code' => 'not_enough_credits', 'message' => 'Insufficient credits'], 400)]],
    'Twilio 400' => [TwilioSms::class, fn () => ['*' => Http::response(['message' => "The 'To' number is not valid"], 400)]],
    'BulkSMS outage' => [BulkSms::class, fn () => ['*' => fn () => throw new ConnectionException('timed out')]],
]);
