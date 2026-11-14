<?php

declare(strict_types=1);

namespace Wurk\Success\Support;

use Illuminate\Support\Facades\Mail;
use Wurk\Security\Contracts\PlatformSettings;
use Wurk\Success\Mail\HealthDigestMail;
use Wurk\Success\Models\BusinessHealth;

/** The weekly digest for WURK's success team (Sprint 16.5): newly at risk, then the most MRR at risk, with what to do. */
final readonly class HealthDigest
{
    public function __construct(private PlatformSettings $settings) {}

    public function recipient(): ?string
    {
        $email = $this->settings->get('success.digest_email');

        return is_string($email) && $email !== '' ? $email : (config('wurk.support_email') ?: null);
    }

    public function send(): bool
    {
        $to = $this->recipient();
        if ($to === null) {
            return false;
        }
        $line = fn (BusinessHealth $h) => sprintf('%s — %d/100%s, R%s/month: %s → %s', $h->name, $h->score, $h->previous_score !== null && $h->previous_score !== $h->score
            ? sprintf(' (was %d)', $h->previous_score) : '', number_format($h->mrr_cents / 100, 0, '.', ' '), $h->signals[0]['reason'] ?? 'No signals', $h->signals[0]['action'] ?? '');
        $new = BusinessHealth::query()->where('grade', 'at_risk')->where('at_risk_since', '>=', now()->subWeek())->orderByDesc('mrr_cents')->limit(20)->get();
        $top = BusinessHealth::query()->whereIn('grade', ['at_risk', 'watch'])->orderByDesc('mrr_cents')->orderBy('score')->limit(20)->get();
        $risk = (int) BusinessHealth::query()->whereIn('grade', ['at_risk', 'watch'])->sum('mrr_cents');
        $counts = BusinessHealth::query()->toBase()->selectRaw('grade, count(*) as n')->groupBy('grade')->pluck('n', 'grade');
        $lines = [
            sprintf('Healthy %d · Watch %d · At risk %d · MRR at risk R%s', $counts['healthy'] ?? 0, $counts['watch'] ?? 0, $counts['at_risk'] ?? 0, number_format($risk / 100, 0, '.', ' ')),
            '', 'NEWLY AT RISK THIS WEEK', ...($new->isEmpty() ? ['None.'] : $new->map($line)->all()),
            '', 'MOST MRR AT RISK', ...($top->isEmpty() ? ['None.'] : $top->map($line)->all()),
        ];
        Mail::to($to)->send(new HealthDigestMail('Business health: '.($counts['at_risk'] ?? 0).' at risk, '.$new->count().' new this week', $lines, route('admin.health')));

        return true;
    }
}
