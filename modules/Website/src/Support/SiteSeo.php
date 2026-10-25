<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

/** Search-engine and AI-assistant outputs, built only from SiteFacts (what the owner published). */
final class SiteSeo
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /**
     * @param  array<string, mixed>  $facts  from SiteFacts::collect()
     * @return array<string, mixed>
     */
    public function localBusiness(array $facts, string $base): array
    {
        $c = $facts['contact'];
        $data = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $facts['name'], 'url' => $base.'/'];
        if ($facts['description'] !== '') {
            $data['description'] = $facts['description'];
        }
        if ($facts['image'] !== null) {
            $data['image'] = $base.$facts['image'];
        }
        if (($c['phone'] ?: $c['whatsapp']) !== '') {
            $data['telephone'] = $c['phone'] ?: $c['whatsapp'];
        }
        if ($c['email'] !== '') {
            $data['email'] = $c['email'];
        }
        if ($c['address'] !== '') {
            $data['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $c['address'], 'addressCountry' => 'ZA'];
        }
        if ($facts['hours'] !== null) {
            $data['openingHoursSpecification'] = [];
            foreach ($facts['hours'] as $i => $h) {
                if ($h['opens'] !== '' && $h['closes'] !== '' && isset(self::DAYS[(int) $i])) { // empty = closed: simply not listed
                    $data['openingHoursSpecification'][] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/'.self::DAYS[(int) $i], 'opens' => $h['opens'], 'closes' => $h['closes']];
                }
            }
        }
        if ($facts['services'] !== []) {
            $data['hasOfferCatalog'] = ['@type' => 'OfferCatalog', 'name' => 'Services', 'itemListElement' => array_map(function (array $s): array {
                $offer = ['@type' => 'Offer', 'itemOffered' => array_filter(['@type' => 'Service', 'name' => $s['name'], 'description' => $s['description'] ?: null])];
                if ($s['price'] > 0) { // 0 means "ask us": no price rather than "free"
                    $offer['price'] = number_format($s['price'] / 100, 2, '.', '');
                    $offer['priceCurrency'] = 'ZAR';
                }

                return $offer;
            }, $facts['services'])];
        }

        return $data;
    }

    /** @param  array<string, mixed>  $facts */
    public function llms(array $facts, string $base): string
    {
        $lines = ["# {$facts['name']}", ''];
        if ($facts['description'] !== '') {
            $lines[] = "> {$facts['description']}";
            $lines[] = '';
        }
        if ($facts['services'] !== []) {
            $lines[] = '## Services and prices';
            foreach ($facts['services'] as $s) {
                $price = $s['price'] > 0 ? ' — '.($s['from'] ? 'from ' : '').'R'.number_format($s['price'] / 100, $s['price'] % 100 === 0 ? 0 : 2, '.', ' ') : ' — ask for a price';
                $lines[] = "- {$s['name']}{$price}".($s['description'] !== '' ? ": {$s['description']}" : '');
            }
            $lines[] = '';
        }
        if ($facts['hours'] !== null) {
            $lines[] = '## Opening hours';
            foreach ($facts['hours'] as $i => $h) {
                if (isset(self::DAYS[(int) $i])) {
                    $lines[] = '- '.self::DAYS[(int) $i].': '.($h['opens'] !== '' ? "{$h['opens']}–{$h['closes']}" : 'closed');
                }
            }
            $lines[] = '';
        }
        $contact = array_filter(['Address' => $facts['contact']['address'], 'Phone' => $facts['contact']['phone'], 'WhatsApp' => $facts['contact']['whatsapp'], 'Email' => $facts['contact']['email']]);
        if ($contact !== []) {
            $lines[] = '## Contact';
            foreach ($contact as $label => $value) {
                $lines[] = "- {$label}: {$value}";
            }
            $lines[] = '';
        }
        $lines[] = '## Pages';
        foreach ($facts['pages'] as $p) {
            $lines[] = "- [{$p['title']}]({$base}{$p['path']})".($p['description'] ? ": {$p['description']}" : '');
        }

        return implode("\n", $lines)."\n";
    }

    /** @param  array<string, mixed>  $facts */
    public function sitemap(array $facts, string $base): string
    {
        $urls = array_map(fn (array $p) => '<url><loc>'.e($base.$p['path']).'</loc>'.($p['lastmod'] ? '<lastmod>'.date('Y-m-d', (int) strtotime((string) $p['lastmod'])).'</lastmod>' : '').'</url>', $facts['pages']);

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>'."\n";
    }

    public function robots(string $base, bool $live): string
    {
        return $live ? "User-agent: *\nAllow: /\n\nSitemap: {$base}/sitemap.xml\n" : "User-agent: *\nDisallow: /\n";
    }
}
