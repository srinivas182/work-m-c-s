<?php

declare(strict_types=1);

namespace Wurk\Ai\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Ai\Languages\DraftCaptionsJob;
use Wurk\Ai\Languages\DraftTranslationsJob;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Help\Contracts\VideoCaptions;
use Wurk\Platform\I18n\Locales;
use Wurk\Platform\I18n\TranslationStore;

/** Admin → Languages (Sprint 24.2): each language's progress, WURK AI drafts, and reviewing texts in context. */
final class LanguagesController
{
    public function __construct(private AuditLog $audit) {}

    public function index(): Response
    {
        $names = array_map(fn ($n) => $n[0], Locales::SUPPORTED);

        return Inertia::render('Ai::Admin/Languages', [
            'languages' => array_map(fn (string $code, array $p) => ['code' => $code, 'name' => $names[$code], ...$p], array_keys($progress = TranslationStore::progress()), $progress),
            'canManage' => Gate::allows('languages.manage'),
        ]);
    }

    public function show(Request $request, string $locale): Response
    {
        abort_unless(Locales::supported($locale) && $locale !== 'en', 404);
        $filter = in_array($request->query('show'), ['missing', 'draft', 'reviewed', 'all'], true) ? (string) $request->query('show') : 'draft';
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));
        $rows = DB::table('translations')->where('locale', $locale)->get(['source', 'text', 'status'])->keyBy('source');
        $files = Locales::fileMessages($locale);
        $items = [];
        foreach (TranslationStore::sourceTexts() as $source) {
            $row = $rows[$source] ?? null;
            $text = $row->text ?? $files[$source] ?? null;
            $status = $row->status ?? ($text !== null ? 'draft' : 'missing');
            if (($filter !== 'all' && $status !== $filter) || ($q !== '' && ! Str::contains(mb_strtolower($source.' '.$text), mb_strtolower($q)))) {
                continue;
            }
            $items[] = ['source' => $source, 'text' => $text, 'status' => $status];
            if (count($items) === 100) {
                break;
            }
        }

        return Inertia::render('Ai::Admin/LanguageTexts', [
            'locale' => $locale, 'name' => Locales::SUPPORTED[$locale][0], 'show' => $filter, 'q' => $q, 'items' => $items,
            'glossary' => DB::table('translation_glossary')->where('locale', $locale)->orderBy('term')->get(['id', 'term', 'translation', 'note'])->all(),
            'canManage' => Gate::allows('languages.manage'),
            'captions' => app(VideoCaptions::class)->forLocale($locale), // 25.6: the help videos' subtitles
        ]);
    }

    /** A reviewer saves a text (reviewed), or keeps it as a draft. */
    public function save(Request $request, string $locale): RedirectResponse
    {
        abort_unless(Locales::supported($locale) && $locale !== 'en', 404);
        $data = $request->validate(['source' => ['required', 'string', 'max:2000', Rule::in(TranslationStore::sourceTexts())], 'text' => ['required', 'string', 'max:4000'], 'reviewed' => ['boolean']]);
        if (! TranslationStore::save($locale, $data['source'], $data['text'], ($data['reviewed'] ?? true) ? 'reviewed' : 'draft', (string) $request->user()?->getAuthIdentifier())) {
            return back()->with('warning', 'Not saved: keep every :placeholder from the English text, exactly as it is.');
        }
        Cache::forget('i18n-offered');
        $this->audit->record('languages.reviewed', 'translations', $locale, ['source' => mb_substr($data['source'], 0, 120)], ['platform' => true]);

        return back()->with('status', 'Saved.');
    }

    public function draft(string $locale): RedirectResponse
    {
        abort_unless(Gate::allows('languages.manage') && Locales::supported($locale) && $locale !== 'en', 403);
        DraftTranslationsJob::dispatch($locale);

        return back()->with('status', 'WURK AI is drafting the missing texts. They appear here as drafts as they’re done.');
    }

    /** WURK AI drafts the help videos' subtitles still missing in this language (25.6). */
    public function draftCaptions(string $locale): RedirectResponse
    {
        abort_unless(Gate::allows('languages.manage') && Locales::supported($locale) && $locale !== 'en', 403);
        DraftCaptionsJob::dispatch($locale);

        return back()->with('status', 'WURK AI is drafting the subtitles. They appear here as drafts to check.');
    }

    /** A reviewer saves a video's subtitles, line for line — approved (shown with the video) or kept as a draft. */
    public function saveCaptions(Request $request, string $locale, string $article, VideoCaptions $captions): RedirectResponse
    {
        abort_unless(Locales::supported($locale) && $locale !== 'en', 404);
        $data = $request->validate(['lines' => ['required', 'array', 'max:120'], 'lines.*' => ['required', 'string', 'max:200'], 'approved' => ['boolean']]);
        if (! $captions->save($article, $locale, array_values(array_map('strval', $data['lines'])), (bool) ($data['approved'] ?? true), (string) $request->user()?->getAuthIdentifier())) {
            return back()->with('warning', 'Not saved: give every subtitle line its words, one for each English line.');
        }

        return back()->with('status', ($data['approved'] ?? true) ? 'Approved — shown with the video.' : 'Saved as a draft.');
    }

    public function toggle(Request $request, string $locale): RedirectResponse
    {
        abort_unless(Gate::allows('languages.manage') && Locales::supported($locale) && $locale !== 'en', 403);
        $on = $request->boolean('enabled');
        DB::table('translation_languages')->updateOrInsert(['locale' => $locale], ['enabled' => $on, 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record('languages.offered', 'translation_languages', $locale, ['enabled' => $on], ['platform' => true]);

        return back()->with('status', $on ? 'Offered to everyone.' : 'No longer offered.');
    }

    public function glossary(Request $request, string $locale): RedirectResponse
    {
        abort_unless(Gate::allows('languages.manage') && Locales::supported($locale) && $locale !== 'en', 403);
        $data = $request->validate(['term' => ['required', 'string', 'max:120'], 'translation' => ['required', 'string', 'max:200'], 'note' => ['nullable', 'string', 'max:300']]);
        DB::table('translation_glossary')->updateOrInsert(['locale' => $locale, 'term' => trim($data['term'])],
            ['id' => (string) Str::ulid(), 'translation' => trim($data['translation']), 'note' => $data['note'] ?? null, 'updated_at' => now(), 'created_at' => now()]);

        return back()->with('status', 'Glossary saved.');
    }
}
