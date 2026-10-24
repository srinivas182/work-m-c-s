<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

/**
 * Privacy, terms and cookies pages for every template, kept in one place so a wording
 * fix reaches every template. Written for small South African businesses under POPIA;
 * {{business}} is replaced with the business's name when its site is built. These are
 * general templates, not legal advice — owners review them before publishing.
 */
final class LegalPages
{
    /** @return list<array<string, mixed>> */
    public static function pages(): array
    {
        $page = fn (string $key, string $title, string $intro, array $sections) => [
            'key' => $key, 'title' => $title, 'slug' => $key, 'is_home' => false,
            'seo_title' => $title.' — {{business}}', 'seo_description' => $intro,
            'content' => ['schema' => 1, 'blocks' => [
                ['id' => 'header', 'type' => 'Header', 'version' => 1, 'props' => []],
                ...array_map(fn (int $i, array $s) => ['id' => "{$key}-{$i}", 'type' => 'Text', 'version' => 1, 'props' => ['heading' => $s[0], 'text' => $s[1]]], array_keys($sections), $sections),
                ['id' => 'footer', 'type' => 'Footer', 'version' => 1, 'props' => []],
            ]],
        ];

        return [
            $page('privacy', 'Privacy policy', 'How {{business}} collects, uses and protects your personal information.', [
                ['Privacy policy', '{{business}} respects your privacy and handles your personal information in line with the Protection of Personal Information Act (POPIA). This policy explains what we collect, why, and your rights.'],
                ['What we collect', 'Only what you give us: your name and contact details when you send an enquiry, request a quote or make a booking, and the details of your request. We do not use advertising trackers on this website.'],
                ['Why we use it', 'To reply to you, provide the service you asked for, and keep the records the law requires. We do not sell your information or use it for anything you have not agreed to.'],
                ['Who sees it', 'Our team, and the service providers that help us run this website and our bookings and payments, under agreements that protect your information. We share it with others only when the law requires it.'],
                ['How long we keep it', 'Only as long as we need it for the purpose you gave it for, or as long as the law requires.'],
                ['Your rights', 'You may ask what information we hold about you, ask us to correct or delete it, or object to how we use it. You may also complain to the Information Regulator (South Africa). To ask, contact us using the details on our contact page.'],
            ]),
            $page('terms', 'Terms of use', 'The terms for using the {{business}} website.', [
                ['Terms of use', 'By using this website you agree to these terms. Please read them with our privacy policy.'],
                ['Information on this website', 'We keep our services, prices and opening hours up to date, but they can change. Please confirm details with us before you rely on them.'],
                ['Bookings, quotes and payments', 'Bookings, quotes and orders are confirmed only once we accept them. Any cancellation, deposit or refund terms are shown when you book or order.'],
                ['Using this website fairly', 'Please do not misuse this website, try to break its security, or copy its content without permission.'],
                ['Contact', 'Questions about these terms? Contact {{business}} using the details on our contact page.'],
            ]),
            $page('cookies', 'Cookies', 'How the {{business}} website uses cookies.', [
                ['Cookies', 'This website uses only the cookies it needs to work — for example to keep a booking or form secure while you complete it. We do not use advertising or tracking cookies, and this website does not load maps, fonts or trackers from other companies.'],
                ['Your choices', 'You can block or delete cookies in your browser settings. Some parts of the website, like forms, may not work without the essential ones.'],
            ]),
        ];
    }

    /**
     * Replace {{business}} (and any other tokens) in every text value of a definition.
     *
     * @param  array<mixed>  $value
     * @param  array<string, string>  $tokens
     * @return array<mixed>
     */
    public static function fill(array $value, array $tokens): array
    {
        array_walk_recursive($value, function (&$v) use ($tokens): void {
            if (is_string($v) && str_contains($v, '{{')) {
                $v = strtr($v, array_combine(array_map(fn ($k) => '{{'.$k.'}}', array_keys($tokens)), array_values($tokens)));
            }
        });

        return $value;
    }
}
