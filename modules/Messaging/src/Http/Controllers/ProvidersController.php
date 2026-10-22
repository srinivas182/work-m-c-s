<?php

declare(strict_types=1);

namespace Wurk\Messaging\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Models\MessageDelivery;
use Wurk\Messaging\Models\ProviderSetting;
use Wurk\Messaging\Support\Drivers;
use Wurk\Messaging\Support\MailConfigurator;
use Wurk\Messaging\Support\ProviderSettings;

/** WURK staff: choose and configure providers per channel; send a test. */
final class ProvidersController
{
    public function __construct(private readonly ProviderSettings $providers) {}

    public function index(): Response
    {
        Gate::authorize('providers.manage');

        return Inertia::render('Messaging::Admin/Providers', [
            'channels' => array_map(fn (array $drivers, string $channel) => [
                'channel' => $channel,
                'drivers' => array_map(fn (array $d, string $key) => ['key' => $key, 'name' => $d['name'], 'fields' => $d['fields'], 'settings' => $d['settings']], $drivers, array_keys($drivers)),
                'current' => $this->providers->present($channel),
            ], Drivers::catalogue(), array_keys(Drivers::catalogue())),
            'recent' => MessageDelivery::query()->whereNull('tenant_id')->latest()->limit(15)->get()
                ->map(fn (MessageDelivery $d) => $d->only(['channel', 'driver', 'to_masked', 'purpose', 'status', 'error']) + ['at' => $d->created_at?->toIso8601String()])->all(),
        ]);
    }

    public function update(Request $request, string $channel): RedirectResponse
    {
        Gate::authorize('providers.manage');
        abort_unless(isset(Drivers::catalogue()[$channel]), 404);
        $data = $request->validate([
            'driver' => ['required', Rule::in(array_keys(Drivers::catalogue()[$channel]))],
            'credentials' => ['array'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
            'settings' => ['array'],
            'settings.*' => ['nullable', 'string', 'max:500'],
            'active' => ['boolean'],
        ]);

        $this->providers->save($channel, $data['driver'], $data['credentials'] ?? [], $data['settings'] ?? [], (bool) ($data['active'] ?? false), (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Provider saved.');
    }

    public function test(Request $request, string $channel, Messenger $messenger): RedirectResponse
    {
        Gate::authorize('providers.manage');
        $setting = ProviderSetting::query()->find($channel) ?? abort(404);
        $data = $request->validate(['to' => ['required', 'string', 'max:255']]);

        $result = match ($channel) {
            'sms' => $this->withActive($setting, fn () => $messenger->sms($data['to'], 'WURK test message: your SMS provider is working.', 'provider_test')),
            'whatsapp' => $this->withActive($setting, fn () => $messenger->whatsappTemplate($data['to'], 'hello_world', [], 'provider_test', 'en_US')),
            'email' => $this->testEmail($setting, $data['to']),
            default => SendResult::failed('Payment gateways are connection-tested when billing goes live (Sprint 3); credentials are stored securely now.'),
        };

        $setting->forceFill(['last_tested_at' => now(), 'last_test_ok' => $result->ok, 'last_test_message' => $result->ok ? 'Sent' : $result->error])->save();

        return back()->with('status', $result->ok ? 'Test sent — check the device or inbox.' : 'Test failed: '.$result->error);
    }

    /** Tests the saved (possibly not yet active) settings without switching them on for everyone. */
    private function withActive(ProviderSetting $setting, callable $send): SendResult
    {
        $wasActive = $setting->active;
        $setting->forceFill(['active' => true])->save();
        try {
            return $send();
        } finally {
            $setting->forceFill(['active' => $wasActive])->save();
        }
    }

    private function testEmail(ProviderSetting $setting, string $to): SendResult
    {
        try {
            app(MailConfigurator::class)->apply($setting);
            Mail::raw('WURK test email: your email provider is working.', fn ($m) => $m->to($to)->subject('WURK email test'));

            return SendResult::sent(null);
        } catch (Throwable $e) {
            return SendResult::failed('Email: '.$e->getMessage());
        } finally {
            app(MailConfigurator::class)->applyActive();
        }
    }
}
