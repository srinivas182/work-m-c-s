<?php

declare(strict_types=1);

namespace Wurk\Marketing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Marketing\Mail\ContactMessageMail;
use Wurk\Plans\Contracts\Catalog;

/** wurk.co.za. Pages are server-rendered for search engines (Inertia SSR on this host only). */
final class MarketingController
{
    public function __construct(private readonly Catalog $catalog) {}

    public function home(): Response
    {
        return $this->page('Marketing::Home', ['packages' => $this->catalog->onSale()]);
    }

    public function pricing(): Response
    {
        return $this->page('Marketing::Pricing', ['packages' => $this->catalog->onSale()]);
    }

    public function faq(): Response
    {
        return $this->page('Marketing::Faq');
    }

    public function contact(): Response
    {
        return $this->page('Marketing::Contact');
    }

    public function legal(string $page): Response
    {
        abort_unless(in_array($page, ['terms', 'privacy'], true), 404);

        return $this->page('Marketing::Legal', ['page' => $page]);
    }

    public function sendContact(Request $request): RedirectResponse
    {
        // Bots fill every field; people never see this one.
        if ($request->filled('website')) {
            return back()->with('status', 'Thanks — we’ll be in touch within one working day.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact' => ['required', 'string', 'max:255'],
            'business' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
        ]);

        DB::table('contact_messages')->insert([...$data, 'id' => (string) Str::ulid(), 'ip' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
        if (($to = config('wurk.contact_email')) !== null && $to !== '') {
            Mail::to((string) $to)->send(new ContactMessageMail([
                'name' => (string) $data['name'],
                'contact' => (string) $data['contact'],
                'business' => isset($data['business']) ? (string) $data['business'] : null,
                'message' => (string) $data['message'],
            ]));
        }

        return back()->with('status', 'Thanks — we’ll be in touch within one working day.');
    }

    /** @param  array<string, mixed>  $props */
    private function page(string $component, array $props = []): Response
    {
        $modules = array_map(fn ($m) => (string) ($m['name'] ?? ''), (array) config('wurk.modules', []));

        return Inertia::render($component, [...$props, 'moduleNames' => $modules, 'signupUrl' => 'https://'.config('wurk.domains.app').'/signup', 'signInUrl' => 'https://'.config('wurk.domains.app').'/sign-in']);
    }
}
