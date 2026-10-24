<?php

declare(strict_types=1);

namespace Wurk\Ai\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Ai\Drivers\AnthropicDriver;
use Wurk\Ai\Drivers\GoogleDriver;
use Wurk\Ai\Drivers\OpenAiDriver;
use Wurk\Ai\Drivers\ProviderFailed;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Models\AiRequest;
use Wurk\Ai\Support\AiSettings;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** WURK staff: Settings → WURK AI (providers' models, prices, margin, caps) and this month's usage. */
final class AiAdminController
{
    public function show(AiSettings $settings, ProviderCredentials $providers, TenantDirectory $tenants): Response
    {
        Gate::authorize('ai.manage');
        $all = $settings->all();
        $fx = (float) $all['fx_zar_per_usd'];
        $month = AiRequest::query()->where('created_at', '>=', now()->startOfMonth());

        $byProvider = (clone $month)->toBase()->selectRaw("provider, count(*) as requests, sum(case when status = 'failed' then 1 else 0 end) as failed, sum(case when fallback = 1 then 1 else 0 end) as fallbacks, sum(cost_usd_micro) as micro")
            ->groupBy('provider')->get()->map(fn ($r) => ['provider' => AiSettings::PROVIDERS[$r->provider] ?? $r->provider, 'requests' => (int) $r->requests, 'failed' => (int) $r->failed, 'fallbacks' => (int) $r->fallbacks, 'usd' => round((int) $r->micro / 1e6, 4), 'zar' => round((int) $r->micro / 1e6 * $fx, 2)])->all();
        $byBusiness = (clone $month)->whereNotNull('tenant_id')->toBase()->selectRaw('tenant_id, count(*) as requests, sum(cost_usd_micro) as micro')
            ->groupBy('tenant_id')->orderByDesc('micro')->limit(20)->get()
            ->map(fn ($r) => ['business' => $tenants->find((string) $r->tenant_id)?->displayName() ?? 'Unknown', 'requests' => (int) $r->requests, 'zar' => round((int) $r->micro / 1e6 * $fx, 2)])->all();

        return Inertia::render('Ai::Admin/Ai', [
            'settings' => $all,
            'providers' => AiSettings::PROVIDERS,
            'tasks' => AiSettings::TASKS,
            'connected' => collect(['anthropic', 'google', 'openai'])->mapWithKeys(fn ($p) => [$p => ($providers->active('ai.'.$p)['credentials']['api_key'] ?? '') !== ''])->all(),
            'usage' => ['byProvider' => $byProvider, 'byBusiness' => $byBusiness, 'totalZar' => round(array_sum(array_column($byProvider, 'zar')), 2)],
        ]);
    }

    public function update(Request $request, AiSettings $settings): RedirectResponse
    {
        Gate::authorize('ai.manage');
        $providers = implode(',', array_keys(AiSettings::PROVIDERS));
        $data = $request->validate([
            'tasks' => ['required', 'array'],
            'tasks.*.provider' => ['required', 'in:'.$providers],
            'tasks.*.model' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9.\-]*$/', 'not_regex:/latest/'], // a pinned version, e.g. claude-sonnet-5-5
            'fallback' => ['array', 'max:4'],
            'fallback.*.provider' => ['required', 'in:'.$providers],
            'fallback.*.model' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9.\-]*$/', 'not_regex:/latest/'],
            'prices' => ['required', 'array'],
            'prices.*' => ['array', 'size:2'],
            'prices.*.*' => ['numeric', 'min:0', 'max:1000'],
            'fx_zar_per_usd' => ['required', 'numeric', 'between:1,200'],
            'fx_buffer_pct' => ['required', 'numeric', 'between:0,50'],
            'target_margin_pct' => ['required', 'numeric', 'between:0,95'],
            'caps.tenant_daily_requests' => ['required', 'integer', 'between:0,10000'],
            'caps.provider_monthly_usd' => ['array'],
            'caps.provider_monthly_usd.*' => ['nullable', 'numeric', 'min:0'],
            'retirements' => ['array'],
            'retirements.*' => ['nullable', 'date'],
        ]);
        if (app()->environment('production') && collect([...$data['tasks'], ...($data['fallback'] ?? [])])->contains('provider', 'simulated')) {
            return back()->withErrors(['tasks' => 'The simulated provider can’t be used in production.']);
        }
        $settings->save($data, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'WURK AI settings saved.');
    }

    /** Try a provider and model before switching: a small sample with its output and cost. */
    public function try(Request $request, AiSettings $settings, ProviderCredentials $providers): JsonResponse
    {
        Gate::authorize('ai.manage');
        $data = $request->validate(['provider' => ['required', 'in:'.implode(',', array_keys(AiSettings::PROVIDERS))], 'model' => ['required', 'string', 'max:80']]);
        $key = $data['provider'] === 'simulated' ? 'simulated' : (string) ($providers->active('ai.'.$data['provider'])['credentials']['api_key'] ?? '');
        if ($key === '') {
            return new JsonResponse(['message' => 'Add this provider’s API key under Providers first.'], 422);
        }
        $driver = match ($data['provider']) {
            'anthropic' => new AnthropicDriver, 'google' => new GoogleDriver, 'openai' => new OpenAiDriver, default => new SimulatedDriver
        };
        try {
            $r = $driver->complete($data['model'], 'You write warm, clear website copy for small South African businesses.', 'Write a two-sentence welcome for Glow Salon, a braids and natural hair salon in Durban North.', 300, $key);
        } catch (ProviderFailed $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }
        $usd = $settings->costMicro($data['provider'], $data['model'], $r['input_tokens'], $r['output_tokens']) / 1e6;

        return new JsonResponse(['text' => $r['text'], 'input_tokens' => $r['input_tokens'], 'output_tokens' => $r['output_tokens'], 'usd' => round($usd, 6)]);
    }
}
