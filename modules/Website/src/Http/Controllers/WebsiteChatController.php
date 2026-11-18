<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\ChatAnswer;
use Wurk\Website\Models\ChatHandoff;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Models\ReceptionistMessage;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\ChatBot;
use Wurk\Website\Support\Receptionist;
use Wurk\Website\Support\SiteFacts;
use Wurk\Website\Support\Uploads\VirusScanner;
use Wurk\Website\Support\WhatsAppChats;

/** Website → Chat (the designer, the AI receptionist and "Try it") and Customers → Chats (hand-offs). */
final class WebsiteChatController
{
    private const MAX_BUTTONS = 8;

    private const MAX_ANSWERS = 30;

    /** The owner's test chats a day: each one is a real AI reply. */
    private const TRIES_PER_DAY = 100;

    public function __construct(private ChatBot $bot, private SiteFacts $facts, private TenantContext $context, private AuditLog $audit, private Receptionist $receptionist) {}

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
            'taps' => DB::connection($this->context->connectionName())->table('chat_taps')->where('tenant_id', $this->context->id())->where('day', '>=', now()->subDays(30)->toDateString())
                ->selectRaw('label, sum(taps) as taps')->groupBy('label')->orderByDesc('taps')->get()->map(fn ($r) => ['label' => (string) $r->label, 'taps' => (int) $r->taps])->all(),
            'receptionist' => $this->receptionistProps(),
        ]);
    }

    /**
     * What the AI receptionist section shows: its settings, what this package lets it do, the price of a reply and
     * this month's use (the owner sees the price before switching it on — spec 19.2).
     *
     * @return array<string, mixed>
     */
    private function receptionistProps(): array
    {
        $entitled = fn (string $module) => app(Entitlements::class)->entitled($this->context->id(), $module);

        return [
            'settings' => $this->receptionist->settings(),
            'can' => ['book' => $entitled('bookings'), 'change' => $entitled('bookings'), 'order' => $entitled('shop')],
            'languages' => Receptionist::LANGUAGES,
            'credits_per_reply' => app(CreditWallet::class)->rateFor(Receptionist::CHANNEL) / 1000,
            'replies_this_month' => $this->receptionist->repliesThisMonth(),
        ];
    }

    public function receptionist(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'enabled' => ['required', 'boolean'],
            'may' => ['present', 'array'], 'may.*' => ['string', Rule::in(['book', 'change', 'order'])],
            'tone' => ['required', Rule::in(['friendly', 'formal'])],
            'greeting' => ['required', 'string', 'max:200'],
            'languages' => ['required', 'array', 'min:1'], 'languages.*' => ['string', Rule::in(array_keys(Receptionist::LANGUAGES))],
            'person_hours' => ['nullable', 'string', 'max:160'],
            'monthly_cap' => ['required', 'integer', 'min:0', 'max:100000'],
        ], ['languages.required' => 'Choose at least one language.']);
        if ($d['enabled'] && ! $this->bot->settings()['enabled']) {
            throw ValidationException::withMessages(['enabled' => 'Switch the chat on first (Settings above): the assistant answers in it.']);
        }
        $this->receptionist->save([
            'enabled' => (bool) $d['enabled'], 'may' => array_values(array_unique($d['may'])), 'tone' => $d['tone'], 'greeting' => trim($d['greeting']),
            'languages' => array_values(array_unique($d['languages'])), 'person_hours' => trim((string) ($d['person_hours'] ?? '')), 'monthly_cap' => (int) $d['monthly_cap'],
        ]);
        $this->audit->record('website.receptionist_settings', 'site', $this->context->id(), ['enabled' => (bool) $d['enabled'], 'may' => $d['may'], 'monthly_cap' => (int) $d['monthly_cap']]);

        return back()->with('status', $d['enabled'] ? 'AI assistant saved — it’s answering in your chat.' : 'AI assistant saved — it’s off.');
    }

    /** "Try it": a test chat with the real facts and free times, where nothing is booked, changed or ordered. */
    public function tryIt(Request $request): JsonResponse
    {
        $d = $request->validate(['message' => ['required', 'string', 'max:2000'], 'conversation' => ['nullable', 'string', 'size:26']]);
        $key = 'receptionist-try:'.$this->context->id().':'.now()->format('Y-m-d');
        if (RateLimiter::tooManyAttempts($key, self::TRIES_PER_DAY)) {
            return new JsonResponse(['message' => 'That’s a lot of test messages for today — try again tomorrow.'], 429);
        }
        RateLimiter::hit($key, 86400);
        $r = $this->receptionist->reply('owner-test-'.$request->user()?->getAuthIdentifier(), $d['message'], $d['conversation'] ?? null, Receptionist::TRY);
        if ($r['mode'] === 'rules') {
            $r['reply'] = 'The assistant can’t reply right now: it may be out of credits, over its monthly limit, or WURK AI may be busy. Customers get your own answers meanwhile.';
        }

        return new JsonResponse($r);
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

    public function chats(Request $request, WhatsAppBusiness $whatsapp): Response
    {
        $tz = $this->context->current()->timezone();
        $show = in_array($request->query('show'), ['archived', 'ai', 'whatsapp'], true) ? (string) $request->query('show') : 'open';

        return Inertia::render('Website::Chats', [
            'show' => $show,
            'assistant' => $this->receptionist->settings()['enabled'] || ReceptionistConversation::query()->whereNotIn('channel', [Receptionist::TRY, WhatsAppChats::CHANNEL])->exists(),
            'whatsapp' => $whatsapp->connected() || ReceptionistConversation::query()->where('channel', WhatsAppChats::CHANNEL)->exists(),
            'follow_up' => $show === 'whatsapp' && $whatsapp->followUpReady(),
            'chats' => in_array($show, ['ai', 'whatsapp'], true) ? [] : ChatHandoff::query()->when($show === 'archived', fn ($q) => $q->where('status', 'archived'), fn ($q) => $q->where('status', '!=', 'archived'))
                ->orderByDesc('created_at')->limit(200)->get()->map(fn (ChatHandoff $c) => [
                    'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'message' => $c->message, 'transcript' => $c->transcript, 'status' => $c->status,
                    'at' => $c->created_at->setTimezone($tz)->format('j M Y, H:i'),
                    'contact_id' => Gate::allows('contacts.view') ? $c->contact_id : null, 'lead_id' => Gate::allows('leads.view') ? $c->lead_id : null,
                ])->all(),
            'conversations' => in_array($show, ['ai', 'whatsapp'], true) ? $this->conversations($tz, $show === 'whatsapp') : [],
        ]);
    }

    /**
     * The AI assistant's conversations with customers (19.4b), or WhatsApp conversations (21.2), newest first: who (when
     * known), the outcome, and what was said by the customer, the assistant and any person (tool steps are left out).
     *
     * @return list<array<string, mixed>>
     */
    private function conversations(string $tz, bool $whatsapp = false): array
    {
        $rows = ReceptionistConversation::query()->when($whatsapp, fn ($q) => $q->where('channel', WhatsAppChats::CHANNEL),
            fn ($q) => $q->whereNotIn('channel', [Receptionist::TRY, WhatsAppChats::CHANNEL]))->orderByDesc('last_message_at')->limit(100)->get();
        $messages = ReceptionistMessage::query()->whereIn('conversation_id', $rows->pluck('id'))->whereIn('role', ['user', 'assistant', 'person'])
            ->orderBy('position')->get()->groupBy('conversation_id');
        $base = route('tenant.website.chats', [], false);

        return array_values($rows->map(fn (ReceptionistConversation $c) => [
            'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'status' => $c->status, 'outcome' => $c->outcome,
            'at' => $c->last_message_at->setTimezone($tz)->format('j M Y, H:i'), 'replies' => $c->replies, 'channel' => $c->channel,
            'window_open' => $c->channel === WhatsAppChats::CHANNEL ? WhatsAppChats::windowOpen($c) : null,
            'window_ends' => $c->channel === WhatsAppChats::CHANNEL && WhatsAppChats::windowOpen($c)
                ? $c->last_inbound_at?->copy()->addHours(WhatsAppChats::WINDOW_HOURS)->setTimezone($tz)->format('j M, H:i') : null,
            'contact_id' => Gate::allows('contacts.view') ? $c->contact_id : null,
            'lines' => ($messages[$c->id] ?? collect())->map(fn (ReceptionistMessage $m) => [
                'from' => ['user' => 'customer', 'assistant' => 'assistant', 'person' => 'person'][$m->role], 'text' => trim((string) ($m->content['content'] ?? '')),
            ] + ($c->channel !== WhatsAppChats::CHANNEL ? [] : [ // ticks and files are WhatsApp's
                'delivery' => $m->delivery, 'error' => $m->delivery_error,
                'file' => isset($m->attachment['path']) ? ['url' => $base.'/ai/'.$c->id.'/files/'.$m->id, 'name' => $m->attachment['name'], 'kind' => $m->attachment['kind']]
                    : (isset($m->attachment['blocked']) ? ['blocked' => $m->attachment['blocked']] : null),
            ]))->filter(fn (array $l) => $l['text'] !== '')->values()->all(),
        ])->all());
    }

    /** A reply on WhatsApp from the business's team: text, a photo or a PDF (21.2). */
    public function reply(Request $request, string $conversation, WhatsAppChats $chats, WhatsAppBusiness $whatsapp, VirusScanner $scanner): RedirectResponse
    {
        $c = ReceptionistConversation::query()->where('channel', WhatsAppChats::CHANNEL)->whereKey($conversation)->firstOrFail();
        $d = $request->validate(['text' => ['nullable', 'required_without:file', 'string', 'max:4096'], 'file' => ['nullable', 'file', 'max:16384']],
            ['text.required_without' => 'Write a message, or attach a photo or PDF.', 'file.max' => 'Files can be up to 16 MB.']);
        $chats->reply($c, trim((string) ($d['text'] ?? '')), $request->file('file'), (string) $request->user()?->getAuthIdentifier(), $whatsapp, $scanner);

        return back()->with('status', 'Sent on WhatsApp.');
    }

    /** After the 24-hour window: the business's approved follow-up message (21.2). */
    public function followUp(Request $request, string $conversation, WhatsAppChats $chats, WhatsAppBusiness $whatsapp): RedirectResponse
    {
        $c = ReceptionistConversation::query()->where('channel', WhatsAppChats::CHANNEL)->whereKey($conversation)->firstOrFail();
        $chats->followUp($c, (string) $request->user()?->getAuthIdentifier(), $whatsapp);

        return back()->with('status', 'Follow-up sent. When they reply, you can write freely again for 24 hours.');
    }

    /** A file from a WhatsApp conversation, for the business's team only (scanned before it was kept). */
    public function file(string $conversation, string $message): HttpResponse
    {
        $m = ReceptionistMessage::query()->where('conversation_id', $conversation)->whereKey($message)->firstOrFail();
        $path = $m->attachment['path'] ?? abort(404);
        $contents = WhatsAppChats::read((string) $path) ?? abort(404);
        $name = preg_replace('/[^\w.\- ]+/u', '_', (string) ($m->attachment['name'] ?? 'file')) ?: 'file';

        return new HttpResponse($contents, 200, ['Content-Type' => (string) ($m->attachment['mime'] ?? 'application/octet-stream'),
            'Content-Disposition' => 'attachment; filename="'.$name.'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** Take over (the assistant stops for this chat), hand it back, or close it. */
    public function conversation(Request $request, string $conversation): RedirectResponse
    {
        /** @var 'take_over'|'hand_back'|'close' $action */
        $action = $request->validate(['action' => ['required', 'in:take_over,hand_back,close']])['action'];
        $c = ReceptionistConversation::query()->where('channel', '!=', Receptionist::TRY)->whereKey($conversation)->firstOrFail();
        $c->update(['take_over' => ['status' => 'person', 'keep' => true], 'hand_back' => ['status' => 'ai'], 'close' => ['status' => 'closed']][$action]);
        $this->audit->record('website.receptionist_'.$action, 'receptionist_conversation', $c->id, []);
        $reach = $c->phone === null && $c->email === null
            ? 'The chat will ask them for a phone number or email so you can reply.'
            : 'Reply to them by phone, WhatsApp or email.';

        return back()->with('status', [
            'take_over' => 'You have this chat: the assistant has stopped. '.$reach,
            'hand_back' => 'The assistant has this chat again.',
            'close' => 'Chat closed.',
        ][$action]);
    }

    /** "Teach it": a question the assistant couldn't answer becomes one of the business's own answers. */
    public function teach(Request $request, string $conversation): RedirectResponse
    {
        ReceptionistConversation::query()->where('channel', '!=', Receptionist::TRY)->whereKey($conversation)->firstOrFail();
        $d = $request->validate(['keywords' => ['required', 'string', 'max:300'], 'answer' => ['required', 'string', 'max:600']],
            ['keywords.required' => 'Add a few words people use when they ask this.']);
        if (ChatAnswer::query()->count() >= self::MAX_ANSWERS) {
            throw ValidationException::withMessages(['answer' => 'You have '.self::MAX_ANSWERS.' answers already — remove one in Website → Chat first.']);
        }
        $a = ChatAnswer::query()->create(['kind' => 'keyword', 'label' => null, 'keywords' => trim($d['keywords']), 'answer' => trim($d['answer']),
            'link_kind' => 'none', 'link_target' => null, 'fact' => null, 'edited' => false, 'position' => (int) ChatAnswer::query()->max('position') + 1]);
        $this->audit->record('website.chat_taught', 'chat_answer', $a->id, ['conversation' => $conversation]);

        return back()->with('status', 'Saved — the assistant (and your chat’s own answers) will use it from now on.');
    }

    public function status(Request $request, string $chat): RedirectResponse
    {
        $status = (string) $request->validate(['status' => ['required', 'in:new,read,archived']])['status'];
        ChatHandoff::query()->whereKey($chat)->firstOrFail()->update(['status' => $status]);

        return back();
    }
}
