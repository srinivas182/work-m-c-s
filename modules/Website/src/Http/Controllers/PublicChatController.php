<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\LeadBook;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Mail\ChatHandoffMail;
use Wurk\Website\Models\ChatAnswer;
use Wurk\Website\Models\ChatHandoff;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\ChatBot;

/** The chat the website loads (only when switched on): greeting, buttons, keyword answers, reply time. */
final class PublicChatController
{
    private const DAILY_CAP = 50;

    public function config(string $handle, TenantDirectory $tenants, TenantContext $context): JsonResponse
    {
        $tenant = $tenants->findByHandle($handle);
        abort_if($tenant === null || ! $tenant->isActive(), 404);

        return $context->run($tenant, function (): JsonResponse {
            $bot = app(ChatBot::class);
            abort_unless($bot->settings()['enabled'], 404);

            return (new JsonResponse($bot->publicConfig()))->setPublic()->setMaxAge(300); // answers change rarely
        });
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

        [$notify, $reply] = $context->run($tenant, function () use ($data, $request): array {
            $bot = app(ChatBot::class);
            abort_unless($bot->settings()['enabled'], 404);
            $news = $request->boolean('news');
            $chat = ChatHandoff::query()->create([
                'name' => $data['name'], 'phone' => $data['phone'] ?? null, 'email' => isset($data['email']) ? mb_strtolower($data['email']) : null,
                'message' => $data['message'] ?? null, 'transcript' => array_values($data['transcript'] ?? []), 'marketing_consent_at' => $news ? now() : null,
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), 'created_at' => now(),
            ]);
            $contact = app(ContactBook::class)->remember($data['name'], $data['phone'] ?? null, $data['email'] ?? null, 'chat', $chat->id, null, 'Website chat', $news ? 'Website chat' : null);
            $lead = $bot->settings()['leads']
                ? app(LeadBook::class)->open($contact, 'Chat: '.$data['name'], 'chat', null, null, ($data['message'] ?? '') !== '' ? $data['message'] : null)
                : null;
            $chat->update(['contact_id' => $contact, 'lead_id' => $lead]);

            return [Site::query()->first()?->settings['enquiry_email'] ?? null, $bot->replyTime()];
        });
        if (is_string($notify) && $notify !== '') {
            Mail::to($notify)->send(new ChatHandoffMail($tenant->displayName(), [
                'name' => (string) $data['name'], 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'message' => $data['message'] ?? null,
                'transcript' => array_values(array_map(fn (array $l) => ['from' => (string) $l['from'], 'text' => (string) $l['text']], $data['transcript'] ?? [])),
            ]));
        }

        return new JsonResponse(['ok' => true, 'reply' => 'Thanks — '.lcfirst($reply)]);
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
