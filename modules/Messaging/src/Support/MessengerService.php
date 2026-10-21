<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Contracts\SmsDriver;
use Wurk\Messaging\Drivers\WhatsApp\MetaCloudWhatsApp;
use Wurk\Messaging\Models\MessageDelivery;
use Wurk\Tenancy\Contracts\TenantContext;

final readonly class MessengerService implements Messenger
{
    public function __construct(
        private ProviderSettings $providers,
        private Container $container,
        private TenantContext $context,
        private CreditWallet $wallet,
    ) {}

    public function sms(string $to, string $text, string $purpose): SendResult
    {
        return $this->charged('sms', $purpose, fn () => $this->sendSms($to, $text, $purpose));
    }

    private function sendSms(string $to, string $text, string $purpose): SendResult
    {
        $provider = $this->providers->active('sms');

        if ($provider === null) {
            return $this->unconfigured('sms', $to, $text, $purpose);
        }

        $class = Drivers::get('sms', $provider->driver)['class'] ?? null;
        /** @var SmsDriver $driver */
        $driver = $this->container->make((string) $class);
        $result = $driver->send($to, $text, $provider->credentials, $provider->settings ?? []);

        return $this->record('sms', $provider->driver, $to, $purpose, $result);
    }

    public function whatsappTemplate(string $to, string $template, array $parameters, string $purpose, string $language = 'en'): SendResult
    {
        return $this->charged('whatsapp', $purpose, fn () => $this->sendWhatsApp($to, $template, $parameters, $purpose, $language));
    }

    /** @param  list<string>  $parameters */
    private function sendWhatsApp(string $to, string $template, array $parameters, string $purpose, string $language): SendResult
    {
        $provider = $this->providers->active('whatsapp');

        if ($provider === null) {
            return $this->unconfigured('whatsapp', $to, $template.' '.implode(' ', $parameters), $purpose);
        }

        $result = $this->container->make(MetaCloudWhatsApp::class)->sendTemplate(
            $to, $template, $parameters, $language, $provider->credentials, $provider->settings ?? [], authentication: $purpose === 'sign_in_code',
        );

        return $this->record('whatsapp', $provider->driver, $to, $purpose, $result);
    }

    /**
     * Messages sent for a business are paid from its WURK Credits (ADR-0008):
     * charged before sending, refunded automatically if the send fails.
     * Platform messages (sign-in codes, invitations) are WURK's own cost.
     *
     * @param  callable(): SendResult  $send
     */
    private function charged(string $channel, string $purpose, callable $send): SendResult
    {
        if (! $this->context->has()) {
            return $send();
        }

        $key = 'msg:'.Str::ulid();
        try {
            $this->wallet->charge($channel, $key, ucfirst(str_replace('_', ' ', $purpose)).' ('.$channel.')');
        } catch (InsufficientCredits $e) {
            return SendResult::failed($e->getMessage());
        }

        $result = $send();
        if (! $result->ok) {
            $this->wallet->refund($key, 'Refund: message not sent');
        }

        return $result;
    }

    public function channelActive(string $channel): bool
    {
        return $this->providers->active($channel) !== null;
    }

    private function record(string $channel, string $driver, string $to, string $purpose, SendResult $result): SendResult
    {
        MessageDelivery::query()->create([
            'tenant_id' => $this->context->has() ? $this->context->id() : null,
            'channel' => $channel,
            'driver' => $driver,
            'to_hash' => hash_hmac('sha256', $to, (string) config('app.key')),
            'to_masked' => self::mask($to),
            'purpose' => $purpose,
            'status' => $result->ok ? 'sent' : 'failed',
            'provider_message_id' => $result->providerId,
            'error' => $result->error,
        ]);

        return $result;
    }

    /** No provider switched on: write to the log locally, refuse loudly in production. */
    private function unconfigured(string $channel, string $to, string $text, string $purpose): SendResult
    {
        if (app()->environment('production')) {
            Log::error("No active {$channel} provider; message not sent.", ['purpose' => $purpose]);

            return $this->record($channel, 'none', $to, $purpose, SendResult::failed("No {$channel} provider is switched on."));
        }

        Log::info("[{$channel} → {$to}] {$text}");

        return $this->record($channel, 'log', $to, $purpose, SendResult::sent(null));
    }

    private static function mask(string $to): string
    {
        return strlen($to) > 7 ? substr($to, 0, 3).' ••• ••• '.substr($to, -4) : '•••';
    }
}
