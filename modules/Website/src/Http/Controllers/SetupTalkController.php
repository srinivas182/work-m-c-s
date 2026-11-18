<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
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

    public function apply(Request $request): RedirectResponse
    {
        $done = $this->talk->apply($this->current($request), (string) $request->user()?->getAuthIdentifier());

        return redirect()->route('tenant.website.pages', ['tenant' => $request->route('tenant')])
            ->with('status', 'All set up: '.implode('; ', $done).'. Check the preview, then publish when you’re happy.');
    }

    public function restart(Request $request): RedirectResponse
    {
        $this->current($request)->update(['status' => 'done']);

        return back();
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
        $rand = fn (int $c) => $c > 0 ? ' R'.number_format($c / 100, 2) : '';
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
