<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Contracts\RefundWindow;
use Wurk\Regions\Contracts\Countries;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;

/**
 * The returns policy page (Sprint 25.5), written from the country pack and the business's own choices.
 *
 * - The return window is the business's `RefundWindow` (the same days WURK enforces on refunds), and the owner
 *   chooses what they offer: a refund, an exchange, store credit.
 * - South Africa ("za-cpa"): the page states the customer's rights under the Consumer Protection Act and the ECT Act,
 *   which no shop policy can take away — 6 months for defective goods (s56, the customer chooses refund, repair or
 *   replacement), 5 days after direct marketing (s16), 10 days when goods don't suit the purpose the customer
 *   stated (s55(3)), 7 days after delivery for online orders (ECT Act). Store credit is never forced on someone
 *   entitled to a refund.
 * - Elsewhere ("generic"): the business's own policy, and a line that local consumer law still applies.
 * - It is a draft until the owner publishes it, and it says plainly that it isn't legal advice; the owner confirms
 *   they've read it before it's written.
 */
final readonly class ReturnsPolicy
{
    public const SLUG = 'returns';

    /** What the business offers on a return within its window. */
    public const OFFERS = ['refund' => 'a refund', 'exchange' => 'an exchange', 'credit' => 'store credit'];

    public function __construct(private TenantContext $context, private Countries $countries, private RefundWindow $window, private Pages $pages,
        private AuditLog $audit) {}

    /** @return array{template: string, days: int, page_id: string|null} */
    public function status(): array
    {
        return ['template' => $this->template(), 'days' => $this->window->days(),
            'page_id' => Page::query()->where('slug', self::SLUG)->value('id')];
    }

    /**
     * Writes (or rewrites) the page as a draft. Nothing changes on the live site until the owner publishes.
     *
     * @param  list<string>  $offers
     */
    public function write(array $offers, bool $confirmed, ?string $by): Page
    {
        if (! $confirmed) {
            throw ValidationException::withMessages(['confirmed' => __('Tick to confirm you’ve read it — it’s a template, not legal advice.')]);
        }
        $offers = array_values(array_intersect(array_keys(self::OFFERS), $offers));
        if ($this->window->days() > 0 && $offers === []) {
            throw ValidationException::withMessages(['offers' => __('Choose at least one thing you offer on a return.')]);
        }
        $this->pages->ensureSite();
        $content = ContentDocument::validate(LegalPages::fill(self::content($this->template(), $this->window->days(), $offers),
            ['business' => $this->context->current()->displayName()]));

        $page = Page::query()->where('slug', self::SLUG)->first();
        if ($page === null) {
            $page = Page::query()->create(['title' => __('Returns policy'), 'slug' => self::SLUG, 'position' => (int) Page::query()->max('position') + 1,
                'seo_title' => __('Returns policy').' — '.$this->context->current()->displayName(),
                'seo_description' => __('When you can return something to :business, and what you get back.', ['business' => $this->context->current()->displayName()]),
                'draft' => json_encode($content), 'updated_by' => $by]);
        } else {
            $page->update(['draft' => json_encode($content), 'draft_revision' => $page->draft_revision + 1, 'updated_by' => $by]);
        }
        $this->audit->record('website.returns_policy_written', 'page', $page->id, ['template' => $this->template(), 'days' => $this->window->days(), 'offers' => $offers, 'by' => $by]);

        return $page;
    }

    private function template(): string
    {
        return (string) ($this->countries->pack($this->context->current()->countryCode())['returns_template'] ?? 'generic');
    }

    /**
     * The page's blocks, with {{business}} still to fill.
     *
     * @param  list<string>  $offers
     * @return array<string, mixed>
     */
    public static function content(string $template, int $days, array $offers): array
    {
        $list = array_map(fn (string $o) => self::OFFERS[$o], $offers);
        $choice = match (count($list)) {
            0 => '',
            1 => $list[0],
            default => implode(', ', array_slice($list, 0, -1)).' or '.end($list),
        };
        $za = $template === 'za-cpa';

        $sections = [
            ['Returns policy', '{{business}} wants you to be happy with what you buy. This page explains when you can bring something back and what you get.'],
            ['Changed your mind?', $days > 0
                ? "You may return goods within {$days} days of buying them, unused, in their packaging and with your receipt or proof of payment. You can choose {$choice}."
                    .(in_array('credit', $offers, true) && in_array('refund', $offers, true) ? ' Store credit is only ever your choice — never instead of a refund you’re entitled to.' : '')
                : 'We don’t take back goods you’ve simply changed your mind about. Your rights below still apply.'],
        ];
        if ($za) {
            $sections[] = ['Your rights under the law', implode("\n", [
                'Whatever this policy says, South African law gives you these rights:',
                '• Defective goods — up to 6 months after you received them, you may return goods that are faulty, unsafe or not of good quality, and choose a refund, a repair or a replacement (Consumer Protection Act, section 56). We pay to get them back.',
                '• Goods that don’t suit what you told us you needed them for — within 10 business days of receiving them (section 55(3)). We pay to get them back.',
                '• Bought after we contacted you (direct marketing) — within 5 business days of receiving the goods, for any reason (section 16). You pay to return them.',
                '• Bought online — within 7 days of delivery, for any reason (Electronic Communications and Transactions Act). You pay to return them.',
                'If you’re entitled to a refund, you may insist on money back; we won’t make you take store credit.',
            ])];
            $sections[] = ['When these rights don’t apply', 'Goods that have been taken apart, altered or combined with other things, goods that can’t be returned for health or safety reasons (such as some personal-care items), and faults we told you about before you bought, which you accepted.'];
        } else {
            $sections[] = ['Your rights under the law', 'This policy doesn’t take away any right the consumer law of your country gives you, for example for goods that are faulty or not as described.'];
        }
        $sections[] = ['How to return something', 'Bring or send it to us with your receipt or proof of payment, and tell us what’s wrong. We refund the same way you paid — card refunds can take a few working days to reach your account.'];
        $sections[] = ['Questions', 'Contact {{business}} using the details on our contact page.'];
        $sections[] = ['About this page', 'This policy was written from a general template and checked by {{business}}. It is not legal advice.'];

        return ['schema' => 1, 'blocks' => [
            ['id' => 'header', 'type' => 'Header', 'version' => 1, 'props' => []],
            ...array_map(fn (int $i, array $s) => ['id' => 'returns-'.$i, 'type' => 'Text', 'version' => 1, 'props' => ['heading' => $s[0], 'text' => $s[1]]], array_keys($sections), $sections),
            ['id' => 'footer', 'type' => 'Footer', 'version' => 1, 'props' => []],
        ]];
    }
}
