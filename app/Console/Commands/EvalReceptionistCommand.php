<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Eval\ReceptionistEval;
use Illuminate\Console\Command;
use Wurk\Ai\Support\AiSettings;

/**
 * Runs the AI receptionist's evaluation set against the real model (Sprint 19.7), on staging before a release. It
 * scores what doesn't depend on wording: the right tools, no booking that shouldn't happen, nothing invented, nothing
 * of another customer's. The same set runs in CI with the model scripted (tests/Feature/Eval).
 */
final class EvalReceptionistCommand extends Command
{
    protected $signature = 'wurk:eval-receptionist
        {--category= : Only this category (booking, moving, cancelling, prices, hours, out_of_scope, abuse, injection, languages, no_free_time, closed_days)}
        {--min=0.9 : The pass rate needed (0–1); below it, the command fails}
        {--json= : Also write the results to this file}';

    protected $description = 'Run the AI receptionist’s 120-conversation evaluation set against the real model';

    public function handle(ReceptionistEval $eval, AiSettings $ai): int
    {
        if (app()->environment('production')) {
            $this->error('Run this on staging: it creates a demo salon and spends AI credits.');

            return self::FAILURE;
        }
        $cases = array_values(array_filter(ReceptionistEval::cases(), fn (array $c) => ! $this->option('category') || $c['category'] === $this->option('category')));
        if ($cases === []) {
            $this->error('No conversations in that category.');

            return self::FAILURE;
        }
        // One salon having many conversations in a day is above a business's normal daily cap: raised for the run only.
        $saved = $ai->all();
        $ai->save([...$saved, 'caps' => [...$saved['caps'], 'tenant_daily_requests' => max(10000, (int) $saved['caps']['tenant_daily_requests'])]], null);
        try {
            $salon = $eval->fixture();
            $results = [];
            $bar = $this->output->createProgressBar(count($cases));
            foreach ($cases as $case) {
                $results[] = ['id' => $case['id'], 'category' => $case['category'], 'language' => $case['language'], 'problems' => $eval->run($case, $salon, live: true)];
                $bar->advance();
            }
            $bar->finish();
            $this->newLine(2);
        } finally {
            $ai->save($saved, null);
        }

        $rows = collect($results)->groupBy('category')->map(fn ($r, $category) => [$category, $r->filter(fn ($x) => $x['problems'] === [])->count().'/'.$r->count()])->values()->all();
        $this->table(['Category', 'Passed'], $rows);
        foreach ($results as $r) {
            foreach ($r['problems'] as $p) {
                $this->line("  ✗ {$r['id']} ({$r['language']}): {$p}");
            }
        }
        $passed = count(array_filter($results, fn ($r) => $r['problems'] === []));
        $rate = $passed / count($results);
        $this->line(sprintf('Passed %d of %d (%.0f%%).', $passed, count($results), $rate * 100));
        if (is_string($this->option('json')) && $this->option('json') !== '') {
            file_put_contents($this->option('json'), (string) json_encode(['passed' => $passed, 'total' => count($results), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $rate >= (float) $this->option('min') ? self::SUCCESS : self::FAILURE;
    }
}
