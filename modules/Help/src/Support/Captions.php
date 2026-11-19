<?php

declare(strict_types=1);

namespace Wurk\Help\Support;

use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Help\Contracts\VideoCaptions;
use Wurk\Help\Models\HelpArticle;
use Wurk\Help\Models\HelpVideoCaption;
use Wurk\Platform\I18n\Locales;

/**
 * Subtitles for the help videos (Sprint 25.6).
 *
 * - English is written with the video, as WebVTT (Admin → Help centre → the article).
 * - Every other language keeps the English cues' timings: only the words are translated (WURK AI drafts them in
 *   Admin → Languages), and a language reviewer approves them before they're shown.
 * - Changing the English words sends the other languages back for review; changing its timings or the number of
 *   lines removes them (they're drafted again).
 */
final readonly class Captions implements VideoCaptions
{
    public const MAX_CUES = 120;

    public const MAX_LINE = 200;

    public function __construct(private AuditLog $audit) {}

    /**
     * WebVTT → cues. Null when it isn't WebVTT with at least one cue.
     *
     * @return list<array{timing: string, text: string}>|null
     */
    public static function parse(string $vtt): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($vtt)) ?: [];
        if (! str_starts_with((string) ($lines[0] ?? ''), 'WEBVTT')) {
            return null;
        }
        $cues = [];
        $current = null;
        foreach (array_slice($lines, 1) as $line) {
            if (preg_match('/^(\d{1,2}:)?\d{2}:\d{2}\.\d{3} --> (\d{1,2}:)?\d{2}:\d{2}\.\d{3}/', $line) === 1) {
                if ($current !== null) {
                    $cues[] = $current;
                }
                $current = ['timing' => trim($line), 'text' => ''];
            } elseif ($current !== null && trim($line) !== '') {
                $current['text'] = trim($current['text']."\n".strip_tags(trim($line)));
            } elseif ($current !== null) {
                $cues[] = $current;
                $current = null;
            }
        }
        if ($current !== null) {
            $cues[] = $current;
        }
        $cues = array_values(array_filter($cues, fn (array $c) => $c['text'] !== ''));

        return $cues === [] || count($cues) > self::MAX_CUES ? null : $cues;
    }

    /** @param list<array{timing: string, text: string}> $cues */
    public static function build(array $cues): string
    {
        return "WEBVTT\n\n".implode("\n\n", array_map(fn (array $c) => $c['timing']."\n".$c['text'], $cues))."\n";
    }

    /** The English subtitles of an article's video, as WebVTT (null: none). */
    public function english(HelpArticle $article): ?string
    {
        return HelpVideoCaption::query()->where('article_id', $article->id)->where('locale', 'en')->value('vtt');
    }

    /** HQ writes (or clears) the English subtitles with the video. */
    public function setEnglish(HelpArticle $article, ?string $vtt, ?string $by): void
    {
        $vtt = trim((string) $vtt);
        $before = HelpVideoCaption::query()->where('article_id', $article->id)->where('locale', 'en')->first();
        if ($vtt === '') {
            HelpVideoCaption::query()->where('article_id', $article->id)->delete(); // no English, nothing to line the others up with

            return;
        }
        $cues = self::parse($vtt) ?? throw ValidationException::withMessages(['captions' => __('Write the subtitles as WebVTT: “WEBVTT”, then each line’s time (00:00.000 --> 00:03.000) and words.')]);
        $old = $before === null ? null : self::parse($before->vtt);
        HelpVideoCaption::query()->updateOrCreate(['article_id' => $article->id, 'locale' => 'en'],
            ['vtt' => self::build($cues), 'status' => 'approved', 'drafted_by_ai' => false, 'reviewed_by' => $by, 'reviewed_at' => now()]);
        $others = HelpVideoCaption::query()->where('article_id', $article->id)->where('locale', '!=', 'en');
        if ($old !== null && array_column($old, 'timing') !== array_column($cues, 'timing')) {
            $others->delete(); // the timings changed: the translations are drafted again
        } elseif ($old !== null && array_column($old, 'text') !== array_column($cues, 'text')) {
            $others->update(['status' => 'draft', 'reviewed_by' => null, 'reviewed_at' => null]); // the words changed: check them again
        }
        $this->audit->record('help.captions_saved', 'help_article', $article->id, ['locale' => 'en', 'cues' => count($cues)], ['platform' => true]);
    }

    public function forLocale(string $locale): array
    {
        $english = HelpVideoCaption::query()->where('locale', 'en')->get()->keyBy('article_id');
        $mine = HelpVideoCaption::query()->where('locale', $locale)->get()->keyBy('article_id');
        $out = [];
        foreach (HelpArticle::query()->whereIn('id', $english->keys())->whereNotNull('video_path')->orderBy('title')->get(['id', 'title']) as $a) {
            $en = self::parse((string) $english->get($a->id)?->vtt) ?? [];
            $row = $mine[$a->id] ?? null;
            $out[] = ['article_id' => $a->id, 'title' => $a->title, 'english' => array_column($en, 'text'),
                'text' => $row === null ? null : array_column(self::parse($row->vtt) ?? [], 'text'), 'status' => $row === null ? 'missing' : $row->status];
        }

        return $out;
    }

    public function save(string $articleId, string $locale, array $texts, bool $approved, ?string $by, bool $byAi = false): bool
    {
        $en = self::parse((string) HelpVideoCaption::query()->where('article_id', $articleId)->where('locale', 'en')->value('vtt'));
        if (! Locales::supported($locale) || $locale === 'en' || $en === null || count($texts) !== count($en)) {
            return false;
        }
        $cues = [];
        foreach ($en as $i => $cue) {
            $text = trim(strip_tags((string) $texts[$i]));
            if ($text === '' || mb_strlen($text) > self::MAX_LINE || str_contains($text, '-->')) {
                return false;
            }
            $cues[] = ['timing' => $cue['timing'], 'text' => $text];
        }
        HelpVideoCaption::query()->updateOrCreate(['article_id' => $articleId, 'locale' => $locale], ['vtt' => self::build($cues), 'status' => $approved ? 'approved' : 'draft',
            'drafted_by_ai' => $byAi, 'reviewed_by' => $approved ? $by : null, 'reviewed_at' => $approved ? now() : null]);
        $this->audit->record($approved ? 'help.captions_approved' : 'help.captions_drafted', 'help_article', $articleId, ['locale' => $locale, 'by' => $by, 'ai' => $byAi], ['platform' => true]);

        return true;
    }

    /**
     * The subtitles shown with the video: English and every approved language. Served from the page's own site
     * (help centre, or the back office's "?" panel), so the player can load them without cross-site requests.
     *
     * @return list<array{locale: string, label: string, url: string}>
     */
    public function tracks(HelpArticle $article, ?string $base = null): array
    {
        $base ??= 'https://'.config('wurk.domains.help').'/a/'.$article->slug.'/captions/';

        return array_values(HelpVideoCaption::query()->where('article_id', $article->id)->where('status', 'approved')->orderByRaw("locale = 'en' desc")->orderBy('locale')
            ->pluck('locale')->filter(fn ($l) => Locales::supported((string) $l))
            ->map(fn ($l) => ['locale' => (string) $l, 'label' => Locales::SUPPORTED[(string) $l][1],
                'url' => $base.$l.'.vtt'])->all());
    }

    /** An approved language's WebVTT, for the video player. */
    public function approved(HelpArticle $article, string $locale): ?string
    {
        return HelpVideoCaption::query()->where('article_id', $article->id)->where('locale', $locale)->where('status', 'approved')->value('vtt');
    }
}
