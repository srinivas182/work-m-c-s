<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Reports\Mail\ReportSummaryMail;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $this->tz = $this->ctx->run($this->glow, fn () => $this->ctx->current()->timezone());
    $this->travelTo(CarbonImmutable::parse('next monday', $this->tz)->subDays(3)->setTime(10, 0)); // a Friday
    $this->ctx->run($this->glow, fn () => event(new PurchaseMade('order', 'o1', 33000, 33000, null, now())));
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->staff = $this->person(['phone' => '+27831000002', 'email' => 'staff@glow.co.za']);
    foreach (['owner' => 'owner', 'staff' => 'staff'] as $who => $role) {
        $this->member($this->$who, $this->glow);
        $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->$who->id, ucfirst($who), $role));
    }
});

it('exports the report as CSV and PDF for those who can see reports', function () {
    $csv = $this->actingAs($this->owner)->get($this->app('/glow-salon/reports/export/csv?period=month'))->assertOk()->getContent();
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", (string) $csv))));
    expect($rows[0])->toBe(['Section', 'Figure', 'This period', 'Previous period'])
        ->and($rows)->toContain(['Takings and VAT', 'Taken (after refunds)', 'R330.00', 'R0.00']);
    $pdf = $this->actingAs($this->owner)->get($this->app('/glow-salon/reports/export/pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
    expect(substr((string) $pdf, 0, 4))->toBe('%PDF');
    $this->actingAs($this->staff)->get($this->app('/glow-salon/reports/export/csv'))->assertForbidden();
});

it('emails owners a weekly summary on Monday morning, once — or monthly, or not at all', function () {
    $this->actingAs($this->staff)->put($this->app('/glow-salon/reports/summary'), ['summary' => 'off'])->assertForbidden(); // owners only
    $this->flushSession();
    $this->travelTo(CarbonImmutable::parse('next monday', $this->tz)->setTime(6, 0));
    $this->artisan('reports:send-summaries');
    Mail::assertNothingSent();                                                    // before 07:00, their time
    $this->travelTo(CarbonImmutable::parse('today', $this->tz)->setTime(8, 0));
    $this->artisan('reports:send-summaries');
    $this->artisan('reports:send-summaries');                                     // not twice
    Mail::assertSent(ReportSummaryMail::class, 1);
    Mail::assertSent(ReportSummaryMail::class, fn (ReportSummaryMail $m) => $m->hasTo('owner@glow.co.za') && $m->span === 'weekly summary'
        && collect($m->sections)->firstWhere('title', 'Takings and VAT')['lines'][0] === 'Taken (after refunds): R330.00 (was R0.00)'
        && str_contains($m->link, '/glow-salon/reports'));
    expect((new ReportSummaryMail('Glow Salon', 'weekly summary', ['One.'], [['title' => 'Takings and VAT', 'lines' => ['Taken: R1.00']]], 'https://x/reports'))->render())
        ->toContain('Your weekly summary for Glow Salon.')->toContain('- One.')->toContain('The full report: https://x/reports');

    $this->actingAs($this->owner)->put($this->app('/glow-salon/reports/summary'), ['summary' => 'monthly'])->assertSessionHas('status', 'Saved.');
    $this->travelTo(CarbonImmutable::parse('today', $this->tz)->addWeek()->setTime(8, 0));  // the next Monday: not monthly's day
    $this->artisan('reports:send-summaries');
    Mail::assertSent(ReportSummaryMail::class, 1);
    $this->travelTo(CarbonImmutable::parse('first day of next month', $this->tz)->setTime(8, 0));
    $this->artisan('reports:send-summaries');
    Mail::assertSent(ReportSummaryMail::class, fn (ReportSummaryMail $m) => $m->span === 'monthly summary');

    $this->flushSession();
    $this->actingAs($this->owner)->put($this->app('/glow-salon/reports/summary'), ['summary' => 'off']);
    $this->travelTo(CarbonImmutable::parse('first day of next month', $this->tz)->setTime(8, 0));
    $this->artisan('reports:send-summaries');
    Mail::assertSent(ReportSummaryMail::class, 2);                                // the weekly and the monthly — nothing after "off"
});
