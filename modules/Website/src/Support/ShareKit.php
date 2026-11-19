<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Platform\Money\Money;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\ProductCatalog;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Models\Site;
use Wurk\Website\Models\SiteAsset;

/**
 * The share kit (Sprint 23.3): what a business can share — its website, booking page, shop, products, current specials
 * and WhatsApp chat — each as a link tagged with where it was shared (utm_source, counted in Reports → Website), an
 * image for WhatsApp Status (9:16, in the business's colours, with a QR code) and printable posters and table cards.
 *
 * Only what is published is offered: a draft page, a hidden product or a special outside its dates never is.
 */
final readonly class ShareKit
{
    /** Where a link was shared: the utm_source each one carries (Reports shows them capitalised). */
    public const SOURCES = ['status', 'whatsapp', 'facebook', 'instagram', 'share', 'poster'];

    /** Poster sizes: dompdf paper and orientation. */
    public const SIZES = ['a4' => ['A4', 'portrait'], 'a5' => ['A5', 'portrait'], 'tent' => ['A4', 'landscape']];

    /** What a poster asks people to do. */
    public const POSTERS = ['book' => 'Scan to book', 'order' => 'Scan to order', 'chat' => 'Scan to chat on WhatsApp', 'site' => 'Scan to visit us'];

    private const FONT = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';

    private const FONT_BOLD = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';

    public function __construct(private TenantContext $context, private ProductCatalog $catalog, private WhatsAppBusiness $whatsapp) {}

    /**
     * Everything the business can share now.
     *
     * @return list<array{key: string, kind: string, title: string, detail: string, url: string}>
     */
    public function items(): array
    {
        $base = $this->base();
        $items = [];
        $special = 0;
        foreach (Page::query()->whereNotNull('published_version_id')->orderByDesc('is_home')->orderBy('position')->get() as $page) {
            $path = $page->is_home ? '/' : '/'.$page->slug;
            $blocks = (array) (json_decode((string) PageVersion::query()->whereKey($page->published_version_id)->value('content'), true)['blocks'] ?? []);
            if ($page->is_home) {
                $items[] = ['key' => 'site', 'kind' => 'site', 'title' => 'Your website', 'detail' => $this->context->current()->displayName(), 'url' => $base.'/'];
            }
            foreach ($blocks as $b) {
                $p = (array) ($b['props'] ?? []);
                $type = (string) ($b['type'] ?? '');
                if ($type === 'BookOnline' && ! $this->has($items, 'book')) {
                    $items[] = ['key' => 'book', 'kind' => 'book', 'title' => 'Book online', 'detail' => 'Choose a time that suits you', 'url' => $base.$path.'#book'];
                }
                if ($type === 'ProductGrid' && ! $this->has($items, 'order')) {
                    $items[] = ['key' => 'order', 'kind' => 'order', 'title' => 'Order online', 'detail' => 'Order and pay on your phone', 'url' => $base.$path];
                }
                if ($type === 'PromotionBanner' && trim((string) ($p['text'] ?? '')) !== '' && self::current((string) ($p['starts'] ?? ''), (string) ($p['ends'] ?? ''))) {
                    $href = (string) ($p['linkHref'] ?? '');
                    $url = str_starts_with($href, '/') ? $base.$href : (str_starts_with($href, 'https://') ? $href : $base.$path);
                    $items[] = ['key' => 'special-'.(++$special), 'kind' => 'special', 'title' => 'Special', 'detail' => mb_substr(trim((string) $p['text']), 0, 140), 'url' => $url];
                }
            }
        }
        foreach (array_slice($this->catalog->published(), 0, 50) as $product) {
            $items[] = ['key' => 'product-'.$product['id'], 'kind' => 'product', 'title' => $product['name'],
                'detail' => ($product['varies'] ? 'From ' : '').self::money($product['price_cents']), 'url' => $base.'/shop/'.$product['slug']];
        }
        if (($chat = $this->chatLink()) !== null) {
            $items[] = ['key' => 'chat', 'kind' => 'chat', 'title' => 'Chat on WhatsApp', 'detail' => $this->whatsapp->chatText(), 'url' => $chat];
        }

        return $items;
    }

    /** @return array{key: string, kind: string, title: string, detail: string, url: string}|null */
    public function item(string $key): ?array
    {
        return collect($this->items())->firstWhere('key', $key);
    }

    /** The link, tagged with where it was shared (a WhatsApp chat link can't carry a tag, so it goes as it is). */
    public static function tagged(string $url, string $source): string
    {
        if (str_starts_with($url, 'https://wa.me/')) {
            return $url;
        }
        $parts = explode('#', $url, 2);

        return $parts[0].(str_contains($parts[0], '?') ? '&' : '?').'utm_source='.$source.(isset($parts[1]) ? '#'.$parts[1] : '');
    }

    /**
     * A WhatsApp Status image (1080 × 1920 PNG): the business's colour, name, what's shared, the link and its QR code.
     *
     * @param  array{key: string, kind: string, title: string, detail: string, url: string}  $item
     */
    public function statusImage(array $item): string
    {
        $brand = $this->branding();
        $img = imagecreatetruecolor(1080, 1920);
        [$r, $g, $b] = self::rgb($brand['primary']);
        $bg = (int) imagecolorallocate($img, $r, $g, $b);
        $light = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160;
        $ink = $light ? (int) imagecolorallocate($img, 17, 17, 17) : (int) imagecolorallocate($img, 255, 255, 255);
        $white = (int) imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, 1079, 1919, $bg);
        $y = 220;
        if (($logo = $this->logo()) !== null && ($l = @imagecreatefromstring($logo)) !== false) {
            $w = 360;
            $h = (int) round(imagesy($l) * $w / max(1, imagesx($l)));
            if ($h > 240) {
                $w = (int) round($w * 240 / $h);
                $h = 240;
            }
            imagecopyresampled($img, $l, (int) ((1080 - $w) / 2), 140, 0, 0, $w, $h, imagesx($l), imagesy($l));
            $y = 140 + $h + 90;
        }
        $y = $this->text($img, $this->context->current()->displayName(), 56, $ink, $y, true);
        $y = $this->text($img, $item['title'], 84, $ink, $y + 60, true);
        if ($item['detail'] !== $this->context->current()->displayName()) {
            $y = $this->text($img, $item['detail'], 48, $ink, $y + 30, false, 4);
        }
        $qr = imagecreatefromstring((new Writer(new GDLibRenderer(560, 4)))->writeString(self::tagged($item['url'], 'status')));
        if ($qr !== false) {
            $top = max($y + 120, 860);
            imagefilledrectangle($img, 230, $top - 30, 849, $top + 589, $white);
            imagecopy($img, $qr, 260, $top, 0, 0, 560, 560);
        }
        $this->text($img, self::address($item['url']), 40, $ink, 1720, true, 1, true);
        ob_start();
        imagepng($img, null, 6);

        return (string) ob_get_clean();
    }

    /** A printable poster or table card (PDF) with the business's QR code, logo and colours. */
    public function poster(string $kind, string $size): string
    {
        $item = $this->posterItem($kind);
        $brand = $this->branding();
        $qr = 'data:image/svg+xml;base64,'.base64_encode((new Writer(new ImageRenderer(new RendererStyle(600, 2), new SvgImageBackEnd)))->writeString(self::tagged($item['url'], 'poster')));
        $logo = $this->logo();
        $html = view('website::share.poster', [
            'business' => $this->context->current()->displayName(), 'call' => self::POSTERS[$kind], 'detail' => in_array($item['kind'], ['chat', 'site'], true) ? '' : $item['detail'],
            'address' => self::address($item['url']), 'qr' => $qr, 'size' => $size,
            'logo' => $logo === null ? null : 'data:'.((new \finfo(FILEINFO_MIME_TYPE))->buffer($logo) ?: 'image/png').';base64,'.base64_encode($logo),
            'primary' => $brand['primary'], 'on' => self::onColour($brand['primary']),
        ])->render();
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper(...self::SIZES[$size]);
        $pdf->render();

        return (string) $pdf->output();
    }

    /** @return array{key: string, kind: string, title: string, detail: string, url: string} */
    public function posterItem(string $kind): array
    {
        return collect($this->items())->firstWhere('kind', $kind) ?? abort(404);
    }

    /**
     * Which posters this business can print (the kinds it has something to share for).
     *
     * @return list<string>
     */
    public function posterKinds(): array
    {
        $kinds = array_column($this->items(), 'kind');

        return array_values(array_filter(array_keys(self::POSTERS), fn ($k) => in_array($k, $kinds, true)));
    }

    private function base(): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return $scheme.'://'.$this->context->current()->handle().'.'.config('wurk.domains.root');
    }

    private function chatLink(): ?string
    {
        try {
            $number = $this->whatsapp->connected() ? $this->whatsapp->number() : null;
        } catch (Throwable) {
            $number = null;
        }

        return $number === null ? null : 'https://wa.me/'.ltrim($number, '+').'?text='.rawurlencode($this->whatsapp->chatText());
    }

    /** @param  list<array{kind: string}>  $items */
    private function has(array $items, string $kind): bool
    {
        return in_array($kind, array_column($items, 'kind'), true);
    }

    /** Whether a special shows today (the same rule as the site: from its start day to its end day, inclusive). */
    private function current(string $starts, string $ends): bool
    {
        $today = now($this->context->current()->timezone())->toDateString();

        return ($starts === '' || $starts <= $today) && ($ends === '' || $ends >= $today);
    }

    /** @return array{primary: string} */
    private function branding(): array
    {
        $primary = (string) (Site::query()->find($this->context->id())?->branding['primary'] ?? '#6D4AFF');

        return ['primary' => preg_match('/^#[0-9A-Fa-f]{6}$/', $primary) === 1 ? strtoupper($primary) : '#6D4AFF'];
    }

    /** The logo's bytes (a WURK-stored asset, read from storage — never fetched from the web). */
    private function logo(): ?string
    {
        $url = (string) (Site::query()->find($this->context->id())?->branding['logo'] ?? '');
        $id = SiteAssets::idsIn(['logo' => $url])[0] ?? null;
        $asset = $id === null ? null : SiteAsset::query()->whereRaw('lower(id) = ?', [$id])->first();
        if ($asset === null || $asset->variants === []) {
            return null;
        }
        $widths = array_map('intval', array_keys($asset->variants));
        sort($widths);
        $pick = collect($widths)->first(fn (int $w) => $w >= 400) ?? end($widths);
        try {
            return Storage::disk((string) config('wurk.site_assets_disk'))->get($asset->variants[$pick]);
        } catch (Throwable) {
            return null;
        }
    }

    /** The link as people read it: no scheme, tag or anchor ("glow-salon.wurk.co.za/contact"). */
    public static function address(string $url): string
    {
        return rtrim((string) preg_replace('#^https?://#', '', (string) strtok(explode('#', $url)[0], '?')), '/');
    }

    /** Centred, wrapped text; returns the y below it. $shrink: one line, made smaller until it fits. */
    private function text(GdImage $img, string $text, int $size, int $colour, int $y, bool $bold, int $maxLines = 2, bool $shrink = false): int
    {
        $font = base_path($bold ? self::FONT_BOLD : self::FONT);
        while ($shrink && $size > 20 && ($box = imagettfbbox($size, 0, $font, $text)) !== false && $box[2] - $box[0] > 980) {
            $size -= 2;
        }
        if ($shrink) {
            $box = imagettfbbox($size, 0, $font, $text);
            imagettftext($img, $size, 0, (int) ((1080 - ($box === false ? 0 : $box[2] - $box[0])) / 2), $y + $size, $colour, $font, $text);

            return $y + $size;
        }
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            $box = imagettfbbox($size, 0, $font, $try);
            if ($box !== false && $box[2] - $box[0] > 940 && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = rtrim(mb_substr($lines[$maxLines - 1], 0, max(1, mb_strlen($lines[$maxLines - 1]) - 1))).'…';
        }
        foreach ($lines as $l) {
            $box = imagettfbbox($size, 0, $font, $l);
            $w = $box === false ? 0 : $box[2] - $box[0];
            $y += (int) round($size * 1.35);
            imagettftext($img, $size, 0, (int) ((1080 - $w) / 2), $y, $colour, $font, $l);
        }

        return $y;
    }

    /** @return array{int<0, 255>, int<0, 255>, int<0, 255>} */
    private static function rgb(string $hex): array
    {
        $c = fn (int $at): int => max(0, min(255, (int) hexdec(substr($hex, $at, 2))));

        return [$c(1), $c(3), $c(5)];
    }

    /** Text colour that reads on the brand colour. */
    public static function onColour(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#111111' : '#FFFFFF';
    }

    private static function money(int $cents): string
    {
        return Money::short((int) $cents);
    }
}
