<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\ChatAnswer;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Support\ChatBot;
use Wurk\Website\Support\Receptionist;

/** The chat the website loads (only when switched on): greeting, buttons, keyword answers, reply time. */
final class PublicChatController
{
    private const DAILY_CAP = 50;

    /** AI receptionist messages per business per day: a backstop against abuse (the owner's monthly cap is the real limit). */
    private const AI_DAILY_CAP = 2000;

    public function config(string $handle, TenantDirectory $tenants, TenantContext $context): JsonResponse
    {
        $tenant = $tenants->findByHandle($handle);
        abort_if($tenant === null || ! $tenant->isActive(), 404);

        return $context->run($tenant, function (): JsonResponse {
            $bot = app(ChatBot::class);
            abort_unless($bot->settings()['enabled'], 404);

            $ai = app(Receptionist::class)->settings();

            return (new JsonResponse([...$bot->publicConfig(),
                // 19.2: when the AI receptionist is on, typed messages go to it (the buttons still answer instantly).
                'ai' => $ai['enabled'] ? ['greeting' => $ai['greeting'], 'notice' => 'You’re chatting with an AI assistant. The conversation is kept for '.ReceptionistConversation::KEEP_DAYS.' days so the business can see it.'] : null,
            ]))->setPublic()->setMaxAge(300); // answers change rarely
        });
    }

    /** 19.2: a typed message to the AI receptionist. The browser keeps its own random key for its conversation. */
    public function message(Request $request, string $handle, TenantDirectory $tenants, TenantContext $context): JsonResponse
    {
        $tenant = $tenants->findByHandle($handle);
        abort_if($tenant === null || ! $tenant->isActive(), 404);
        $data = $request->validate([
            'visitor' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'message' => ['required', 'string', 'max:2000'],
            'conversation' => ['nullable', 'string', 'size:26'],
        ]);
        $capKey = 'chat-ai-cap:'.$tenant->tenantId().':'.now()->format('Y-m-d');
        abort_if(RateLimiter::tooManyAttempts($capKey, self::AI_DAILY_CAP), 429);
        RateLimiter::hit($capKey, 86400);

        return new JsonResponse($context->run($tenant, function () use ($data): array {
            abort_unless(app(ChatBot::class)->settings()['enabled'], 404);

            return app(Receptionist::class)->reply($data['visitor'], $data['message'], $data['conversation'] ?? null);
        }));
    }

    /** Decision A: a chat is stored only when the visitor asks for a person (with consent). */
    public function handoff(Request $request, string $handle, TenantDirectory $tenants, TenantContext $context): JsonResponse
    {
        $tenant = $tenants->findByHandle($handle);
        abort_if($tenant === null || ! $tenant->isActive(), 404);
        if (trim((string) $request->input('website', '')) !== '') {
            return new JsonResponse(['ok' => true]); // bots fill in every field; people never see this one
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[+0-9 ()-]{6,20}$/', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:120', 'required_without:phone'],
            'message' => ['nullable', 'string', 'max:1000'],
            'transcript' => ['nullable', 'array', 'max:30'],
            'transcript.*.from' => ['required', 'in:visitor,bot'],
            'transcript.*.text' => ['required', 'string', 'max:600'],
            'consent' => ['accepted'],
            'news' => ['nullable'],
        ], [
            'phone.required_without' => 'Give a phone number or an email so we can reply.',
            'email.required_without' => 'Give a phone number or an email so we can reply.',
            'consent.accepted' => 'Please agree to be contacted about this.',
        ]);
        $capKey = 'chat-cap:'.$tenant->tenantId().':'.now()->format('Y-m-d');
        abort_if(RateLimiter::tooManyAttempts($capKey, self::DAILY_CAP), 429);
        RateLimiter::hit($capKey, 86400);

        $reply = $context->run($tenant, function () use ($data, $request): string {
            abort_unless(app(ChatBot::class)->settings()['enabled'], 404);

            return app(ChatBot::class)->handOff($data, array_values($data['transcript'] ?? []), hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), $request->boolean('news'));
        });

        return new JsonResponse(['ok' => true, 'reply' => $reply]);
    }

    /** Anonymous: count a button tap (no visitor details), so owners can see which answers are used. */
    public function tap(Request $request, string $handle, TenantDirectory $tenants, TenantContext $context): JsonResponse
    {
        $tenant = $tenants->findByHandle($handle);
        abort_if($tenant === null, 404);
        $label = mb_substr(trim((string) $request->input('label', '')), 0, 30);
        $context->run($tenant, function () use ($label, $context): void {
            if ($label === '' || ! ChatAnswer::query()->where('kind', 'button')->where('label', $label)->exists()) {
                return; // only the business's own buttons
            }
            $db = DB::connection($context->connectionName());
            $key = ['tenant_id' => $context->id(), 'day' => now()->toDateString(), 'label' => $label];
            $db->table('chat_taps')->where($key)->exists() ? $db->table('chat_taps')->where($key)->increment('taps') : $db->table('chat_taps')->insert([...$key, 'taps' => 1]);
        });

        return new JsonResponse(['ok' => true]);
    }
}
