<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\ChatAnswer;
use Wurk\Website\Models\ChatHandoff;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\ChatBot;
use Wurk\Website\Support\SiteFacts;

/** Website → Chat (the designer) and Customers → Chats (hand-offs). */
final class WebsiteChatController
{
    private const MAX_BUTTONS = 8;

    private const MAX_ANSWERS = 30;

    public function __construct(private ChatBot $bot, private SiteFacts $facts, private TenantContext $context, private AuditLog $audit) {}

    public function designer(): Response
    {
        $this->bot->ensureReady();
        $facts = $this->facts->collect();

        return Inertia::render('Website::Chat', [
            'settings' => $this->bot->settings(),
            'answers' => ChatAnswer::query()->orderBy('position')->get()->map(fn (ChatAnswer $a) => [
                'id' => $a->id, 'kind' => $a->kind, 'label' => $a->label, 'keywords' => $a->keywords, 'link_kind' => $a->link_kind, 'link_target' => $a->link_target,
                'fact' => $a->fact, 'edited' => $a->edited,
                'answer' => $a->fact !== null && ! $a->edited ? ($this->bot->factAnswer($a->fact, $facts) ?? $a->answer) : $a->answer, // what visitors see today
            ])->all(),
            'pages' => array_map(fn (array $p) => ['path' => $p['path'], 'title' => $p['title']], $facts['pages']),
            'preview' => $this->bot->publicConfig(),
            'taps' => DB::connection($this->context->connectionName())->table('chat_taps')->where('day', '>=', now()->subDays(30)->toDateString())
                ->selectRaw('label, sum(taps) as taps')->groupBy('label')->orderByDesc('taps')->get()->map(fn ($r) => ['label' => (string) $r->label, 'taps' => (int) $r->taps])->all(),
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $paths = array_column($this->facts->collect()['pages'], 'path');
        $d = $request->validate([
            'enabled' => ['required', 'boolean'], 'greeting' => ['required', 'string', 'max:200'], 'fallback' => ['required', 'string', 'max:200'],
            'hidden' => ['array', 'max:50'], 'hidden.*' => ['string', Rule::in($paths)], 'leads' => ['required', 'boolean'],
        ]);
        $site = Site::query()->firstOrFail();
        $site->update(['settings' => [...(array) $site->settings, 'chat' => ['enabled' => (bool) $d['enabled'], 'greeting' => trim($d['greeting']), 'fallback' => trim($d['fallback']),
            'hidden' => array_values($d['hidden'] ?? []), 'leads' => (bool) $d['leads']]]]);
        $this->audit->record('website.chat_settings', 'site', $site->getKey(), ['enabled' => (bool) $d['enabled']]);

        return back()->with('status', $d['enabled'] ? 'Chat saved — it’s on your website.' : 'Chat saved — it’s off.');
    }

    /** Replace the answers (buttons and keyword answers) in the order given. */
    public function answers(Request $request): RedirectResponse
    {
        $facts = $this->facts->collect();
        $d = $request->validate([
            'answers' => ['present', 'array', 'max:'.self::MAX_ANSWERS],
            'answers.*.id' => ['nullable', 'string'], 'answers.*.kind' => ['required', 'in:button,keyword'],
            'answers.*.label' => ['nullable', 'required_if:answers.*.kind,button', 'string', 'max:30'],
            'answers.*.keywords' => ['nullable', 'required_if:answers.*.kind,keyword', 'string', 'max:300'],
            'answers.*.answer' => ['required', 'string', 'max:600'],
            'answers.*.link_kind' => ['required', 'in:none,page,call,whatsapp,map,email'],
            'answers.*.link_target' => ['nullable', 'required_if:answers.*.link_kind,page', Rule::in(array_column($facts['pages'], 'path'))],
            'answers.*.reset' => ['nullable', 'boolean'],
        ]);
        if (count(array_filter($d['answers'], fn ($a) => $a['kind'] === 'button')) > self::MAX_BUTTONS) {
            throw ValidationException::withMessages(['answers' => 'Up to '.self::MAX_BUTTONS.' buttons — turn some into keyword answers.']);
        }
        DB::connection($this->context->connectionName())->transaction(function () use ($d, $facts): void {
            $old = ChatAnswer::query()->get()->keyBy('id');
            ChatAnswer::query()->whereNotIn('id', array_filter(array_column($d['answers'], 'id')))->delete();
            foreach (array_values($d['answers']) as $i => $a) {
                $prev = isset($a['id']) ? ($old[$a['id']] ?? null) : null;
                $fact = $prev?->fact;
                $text = trim($a['answer']);
                // A ready-made answer becomes the owner's own only when its words differ from today's facts.
                $edited = $fact !== null && empty($a['reset']) && $text !== ($this->bot->factAnswer($fact, $facts) ?? '');
                $row = ['kind' => $a['kind'], 'label' => $a['kind'] === 'button' ? trim((string) $a['label']) : null, 'keywords' => isset($a['keywords']) ? trim((string) $a['keywords']) : null,
                    'answer' => $text, 'link_kind' => $a['link_kind'], 'link_target' => $a['link_kind'] === 'page' ? $a['link_target'] : null, 'fact' => $fact, 'edited' => $edited, 'position' => $i];
                $prev ? $prev->update($row) : ChatAnswer::query()->create($row);
            }
        });
        $this->audit->record('website.chat_answers', 'site', $this->context->id(), ['count' => count($d['answers'])]);

        return back()->with('status', 'Answers saved.');
    }

    public function chats(Request $request): Response
    {
        $tz = $this->context->current()->timezone();
        $show = $request->query('show') === 'archived' ? 'archived' : 'open';

        return Inertia::render('Website::Chats', [
            'show' => $show,
            'chats' => ChatHandoff::query()->when($show === 'archived', fn ($q) => $q->where('status', 'archived'), fn ($q) => $q->where('status', '!=', 'archived'))
                ->orderByDesc('created_at')->limit(200)->get()->map(fn (ChatHandoff $c) => [
                    'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'message' => $c->message, 'transcript' => $c->transcript, 'status' => $c->status,
                    'at' => $c->created_at->setTimezone($tz)->format('j M Y, H:i'),
                    'contact_id' => Gate::allows('contacts.view') ? $c->contact_id : null, 'lead_id' => Gate::allows('leads.view') ? $c->lead_id : null,
                ])->all(),
        ]);
    }

    public function status(Request $request, string $chat): RedirectResponse
    {
        $status = (string) $request->validate(['status' => ['required', 'in:new,read,archived']])['status'];
        ChatHandoff::query()->whereKey($chat)->firstOrFail()->update(['status' => $status]);

        return back();
    }
}
