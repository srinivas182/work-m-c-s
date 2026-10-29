<?php

declare(strict_types=1);

namespace Wurk\Crm\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Contracts\BranchRestriction;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Crm\Models\Contact;
use Wurk\Crm\Support\Contacts;
use Wurk\Crm\Support\Phone;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

/** Customers → Contacts: one row per person, with what they've done. Branch managers see their branches' contacts. */
final class ContactsController
{
    public function __construct(private TenantContext $context, private Contacts $contacts, private AuditLog $audit) {}

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $tag = trim((string) $request->query('tag', ''));
        $page = $this->visible($request)->when($q !== '', function (Builder $b) use ($q): void {
            $phone = Phone::normalise($q);
            $b->where(fn ($w) => $w->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.mb_strtolower($q).'%')
                ->when($phone !== null && strlen($phone) > 5, fn ($w) => $w->orWhere('phone', 'like', '%'.ltrim((string) $phone, '+').'%')));
        })->when($tag !== '', fn (Builder $b) => $b->where('tags', 'like', '%"'.str_replace(['%', '"'], '', $tag).'"%'))
            ->orderByDesc('last_seen_at')->paginate(50)->withQueryString();

        return Inertia::render('Crm::Contacts', [
            'contacts' => array_map(fn (Contact $c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'tags' => $c->tags ?? [],
                'last_seen' => $c->last_seen_at?->setTimezone($this->context->current()->timezone())->format('j M Y')], $page->items()),
            'pages' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total()],
            'q' => $q, 'tag' => $tag, 'canManage' => Gate::allows('contacts.manage'),
        ]);
    }

    public function show(Request $request, string $contact): Response
    {
        $c = $this->visible($request)->whereKey($contact)->firstOrFail();
        $names = array_column(app(BranchDirectory::class)->all(), 'name', 'id');
        $tz = $this->context->current()->timezone();

        return Inertia::render('Crm::Contact', [
            'contact' => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'tags' => $c->tags ?? [], 'notes' => $c->notes,
                'consent' => $c->marketing_consent_at === null ? null : ['at' => $c->marketing_consent_at->setTimezone($tz)->format('j M Y'), 'source' => $c->marketing_consent_source]],
            'history' => DB::connection($this->context->connectionName())->table('contact_links')->where('contact_id', $c->id)->orderByDesc('occurred_at')->limit(200)->get()
                ->map(fn ($l) => ['type' => (string) $l->source_type, 'summary' => (string) $l->summary, 'branch' => $names[(string) $l->branch_id] ?? null,
                    'at' => CarbonImmutable::parse((string) $l->occurred_at)->setTimezone($tz)->format('j M Y')])->all(),
            'merged' => Contact::query()->where('merged_into', $c->id)->get(['id', 'name'])->map(fn (Contact $m) => ['id' => $m->id, 'name' => $m->name])->all(),
            'canManage' => Gate::allows('contacts.manage'),
        ]);
    }

    public function update(Request $request, string $contact): RedirectResponse
    {
        Gate::authorize('contacts.manage');
        $c = $this->visible($request)->whereKey($contact)->firstOrFail();
        $d = $request->validate(['name' => ['required', 'string', 'max:160'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:160'],
            'tags' => ['array', 'max:20'], 'tags.*' => ['string', 'max:40'], 'notes' => ['nullable', 'string', 'max:5000']]);
        $c->update(['name' => trim($d['name']), 'phone' => Phone::normalise($d['phone'] ?? null), 'email' => isset($d['email']) ? mb_strtolower($d['email']) : null,
            'tags' => array_values(array_unique(array_map('trim', $d['tags'] ?? []))), 'notes' => $d['notes'] ?? null]);
        $this->audit->record('contacts.updated', 'contact', $c->id, []);

        return back()->with('status', 'Contact saved.');
    }

    public function merge(Request $request, string $contact): RedirectResponse
    {
        Gate::authorize('contacts.manage');
        $keep = $this->visible($request)->whereKey($contact)->firstOrFail();
        $other = $this->visible($request)->whereKey((string) $request->validate(['other' => ['required', 'string']])['other'])->firstOrFail();
        $this->contacts->merge($keep, $other);
        $this->audit->record('contacts.merged', 'contact', $keep->id, ['merged' => $other->id]);

        return back()->with('status', "{$other->name} merged into {$keep->name}. You can split them again from this page.");
    }

    public function split(Request $request, string $contact, string $merged): RedirectResponse
    {
        Gate::authorize('contacts.manage');
        $m = Contact::query()->whereKey($merged)->where('merged_into', $contact)->firstOrFail();
        $this->contacts->split($m);
        $this->audit->record('contacts.split', 'contact', $contact, ['split' => $m->id]);

        return back()->with('status', "{$m->name} is a separate contact again.");
    }

    /**
     * CSV of the contacts this person can see (POPIA: logged). Built before responding: a streamed response would run
     * after the business's database context has ended.
     */
    public function export(Request $request): HttpResponse
    {
        Gate::authorize('contacts.manage');
        $this->audit->record('contacts.exported', 'contact', 'all', []);
        $out = fopen('php://temp', 'r+');
        abort_if($out === false, 500);
        fputcsv($out, ['Name', 'Phone', 'Email', 'Tags', 'Marketing consent', 'Last seen'], escape: '');
        foreach ($this->visible($request)->orderBy('name')->lazy(500) as $c) {
            fputcsv($out, [$c->name, $c->phone, $c->email, implode(', ', $c->tags ?? []), $c->marketing_consent_at?->format('Y-m-d') ?? '', $c->last_seen_at?->format('Y-m-d') ?? ''], escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return new HttpResponse($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="contacts.csv"', 'Cache-Control' => 'no-store']);
    }

    /** @return Builder<Contact> contacts this person may see: not merged away; branch managers only their branches' */
    private function visible(Request $request): Builder
    {
        $limit = app(BranchRestriction::class)->branchRestriction((string) $request->user()?->getAuthIdentifier());

        return Contact::query()->whereNull('merged_into')->when($limit !== null, fn (Builder $b) => $b->whereIn('id',
            DB::connection($this->context->connectionName())->table('contact_links')->whereIn('branch_id', $limit)->select('contact_id')));
    }
}
