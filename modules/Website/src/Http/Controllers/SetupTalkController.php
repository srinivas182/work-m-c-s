<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Wurk\Platform\Money\Money;
use Wurk\Website\Models\SetupInterview;
use Wurk\Website\Models\SiteCategory;
use Wurk\Website\Setup\WebsiteSetupStep;
use Wurk\Website\Support\OnlineImport;
use Wurk\Website\Support\SetupTalk;

/** Owners: Website → Set up by talking (23.1). Six questions, typed or spoken; then one confirmed set-up. */
final class SetupTalkController
{
    public function __construct(private SetupTalk $talk) {}

    public function show(Request $request): Response
    {
        $i = $this->talk->current((string) $request->user()?->getAuthIdentifier());
        $next = SetupTalk::next($i);

        return Inertia::render('Website::SetupTalk', [
            'questions' => array_map(fn (string $key) => ['key' => $key, 'question' => SetupTalk::QUESTIONS[$key], 'answer' => $i->answers[$key] ?? null,
                'understood' => array_key_exists($key, $i->answers) ? self::understood($key, $i->brief) : null], array_keys(SetupTalk::QUESTIONS)),
            'next' => $next,
            'preview' => $next === null ? $this->talk->preview($i) : null,
            'started' => $i->answers !== [],
            'photos' => array_map(fn (array $p) => ['source' => $p['source'], 'chosen' => $p['chosen']], array_values((array) ($i->brief['photos'] ?? []))),
            'reviews' => ($i->brief['reviews_url'] ?? '') !== '',
            'return' => self::returnTo($request->query('return')), // 23.4: an agent's setup flow, to carry on there
        ]);
    }

    public function answer(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', Rule::in(array_keys(SetupTalk::QUESTIONS))], 'text' => ['required', 'string', 'max:'.SetupTalk::MAX_ANSWER]]);
        $this->talk->answer($this->current($request), $data['key'], $data['text']);

        return back();
    }

    public function voice(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', Rule::in(array_keys(SetupTalk::QUESTIONS))],
            'audio' => ['required', 'file', 'max:10240', 'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/wav,audio/x-wav,audio/aac,video/webm']]);
        $this->talk->answerVoice($this->current($request), $data['key'], $data['audio']);

        return back();
    }

    /** 23.2: start from a Google listing, Facebook page, Instagram profile or website (or a business name and area). */
    public function import(Request $request, OnlineImport $import): RedirectResponse
    {
        $link = (string) $request->validate(['link' => ['required', 'string', 'max:500']])['link'];
        $answered = $import->import($this->current($request), $link);

        return back()->with('status', 'Filled in '.count($answered).' of '.count(SetupTalk::QUESTIONS).' answers. Check each one, change anything that’s wrong, and answer the rest.');
    }

    /** 23.2: a found photo's thumbnail, fetched through WURK (the business's pages aren't trusted to load in the app). */
    public function photo(Request $request, OnlineImport $import, int $n): SymfonyResponse
    {
        $url = (string) ($this->current($request)->brief['photos'][$n]['url'] ?? '');
        abort_if($url === '', 404);
        ['body' => $body, 'type' => $type] = $import->photo($url);

        return response($body, 200, ['Content-Type' => $type, 'Cache-Control' => 'private, max-age=600', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    /** 23.2: which of the photos found the owner wants on their website (only theirs are ever offered). */
    public function photos(Request $request): RedirectResponse
    {
        $i = $this->current($request);
        $photos = array_values((array) ($i->brief['photos'] ?? []));
        $chosen = array_map('intval', (array) $request->validate(['chosen' => ['present', 'array', 'max:'.OnlineImport::MAX_PHOTOS], 'chosen.*' => ['integer', 'min:0', 'max:'.max(0, count($photos) - 1)]])['chosen']);
        foreach ($photos as $n => $photo) {
            $photos[$n]['chosen'] = in_array($n, $chosen, true);
        }
        $i->update(['brief' => [...$i->brief, 'photos' => $photos]]);

        return back();
    }

    public function apply(Request $request): RedirectResponse
    {
        $done = $this->talk->apply($this->current($request), (string) $request->user()?->getAuthIdentifier());
        if (($return = self::returnTo($request->input('return'))) !== null) {
            return redirect($return)->with('status', 'All set up: '.implode('; ', $done).'.');
        }

        return redirect()->route('tenant.website.pages', ['tenant' => $request->route('tenant')])
            ->with('status', 'All set up: '.implode('; ', $done).'. Check the preview, then publish when you’re happy.');
    }

    public function restart(Request $request): RedirectResponse
    {
        $this->current($request)->update(['status' => 'done']);

        return back();
    }

    /** Where to go after setting up: only a path on this site (never another site). */
    private static function returnTo(mixed $path): ?string
    {
        return is_string($path) && preg_match('#^/[A-Za-z0-9/_-]{0,200}$#', $path) === 1 ? $path : null;
    }

    private function current(Request $request): SetupInterview
    {
        return $this->talk->current((string) $request->user()?->getAuthIdentifier());
    }

    /**
     * What WURK understood from one answer, in a line.
     *
     * @param  array<string, mixed>  $b
     */
    private static function understood(string $key, array $b): string
    {
        $rand = fn (int $c) => $c > 0 ? ' '.Money::format((int) $c) : '';
        $or = fn (string $s) => $s !== '' ? $s : '—';

        return match ($key) {
            'business' => $or($b['name']).($b['what'] !== '' ? ' — '.$b['what'] : '').($b['category'] !== '' ? ' ('.(SiteCategory::query()->where('key', $b['category'])->value('name') ?? $b['category']).')' : ''),
            'area' => $or(implode(', ', array_filter([$b['area'], $b['address']]))),
            'offer' => $or(implode(', ', array_map(fn ($s) => $s['name'].$rand($s['price_cents']), [...$b['services'], ...$b['products']]))),
            'hours' => $or(WebsiteSetupStep::hoursText((array) $b['hours'])),
            'contact' => $or(implode(', ', array_filter([$b['phone'], $b['whatsapp'] !== '' ? 'WhatsApp '.$b['whatsapp'] : '', $b['email']]))),
            default => implode(' and ', array_filter([$b['bookings'] ? 'Online bookings' : '', $b['shop'] ? 'Online orders' : ''])) ?: 'Website only',
        };
    }
}
