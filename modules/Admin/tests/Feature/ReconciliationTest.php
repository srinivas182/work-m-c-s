<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Messaging\Models\MessageDelivery;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-04-02 10:00', 'Africa/Johannesburg'));
    $this->createShards(1);
    $glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $sent = function (?string $pid, string $to, string $at, string $status = 'sent') use ($glow) {
        $d = MessageDelivery::query()->create(['tenant_id' => $glow->id, 'channel' => 'sms', 'driver' => 'bulksms', 'to_hash' => hash_hmac('sha256', $to, (string) config('app.key')),
            'to_masked' => '+27 82 *** **'.substr($to, -2), 'purpose' => 'campaign', 'status' => $status, 'provider_message_id' => $pid]);
        $d->forceFill(['created_at' => CarbonImmutable::parse($at, 'Africa/Johannesburg')])->save();
    };
    $sent('P1', '+27820000001', '2027-03-05 10:00');
    $sent('P2', '+27820000002', '2027-03-05 11:00');
    $sent('P3', '+27820000003', '2027-03-05 12:00', 'failed');   // providers don't bill failures
    $sent(null, '+27820000004', '2027-03-06 09:00');             // sent, but no provider ID
    $sent('P5', '+27820000005', '2027-03-06 00:30');             // just after midnight in South Africa: still 6 March
    DB::table('cost_settings')->insert(['kind' => 'provider', 'channel' => 'sms', 'country' => '', 'package_id' => '', 'cents' => 25000, 'created_at' => now(), 'updated_at' => now()]); // R250 per 1 000
    $this->finance = $this->staffWithRole('finance', 'finance@wurk.co.za');
    $csv = "\u{FEFF}Date;Message ID;Recipient;Cost\n05/03/2027;P1;0820000001;R0.25\n5/3/2027;P2;27820000002;\"R0,25\"\n06/03/2027;P9;0820000009;R0.30\n06/03/2027;;;R0.25\n";
    $this->actingAs($this->finance)->post($this->admin('/reconciliation'), ['statement' => UploadedFile::fake()->createWithContent('bulksms-march.csv', $csv)])
        ->assertSessionHas('status', '4 lines read. Choose the columns below.');
    $this->check = fn (array $cols) => $this->actingAs($this->finance)->post($this->admin('/reconciliation/run'), ['driver' => 'bulksms', 'from' => '2027-03-01', 'to' => '2027-03-31', ...$cols]);
    $this->result = fn () => $this->actingAs($this->finance)->get($this->admin('/reconciliation'))->viewData('page')['props']['result'];
});

it('matches a statement by message ID — billed-not-sent, sent-not-billed, and the cost difference', function () {
    $this->actingAs($this->finance)->get($this->admin('/reconciliation'))->assertInertia(fn (Assert $p) => $p->where('upload.columns', ['Date', 'Message ID', 'Recipient', 'Cost'])
        ->where('upload.rows', 4)->where('drivers', ['bulksms']));
    ($this->check)(['id' => 1, 'date' => 0, 'cost' => 3])->assertSessionHasNoErrors();
    expect(($this->result)())->toMatchArray(['matchedBy' => 'message ID', 'statement' => 4, 'wurk' => 4, 'matched' => 2, 'statementOnly' => 1, 'wurkOnly' => 2, 'unreadable' => 1,
        'statementCents' => 105, 'expectedCents' => 100, 'differenceCents' => 5])
        ->and(($this->result)()['statementOnlyRows'])->toBe([['2027-03-06', 'P9', 'R0.30']]);
    $csv = (string) $this->actingAs($this->finance)->get($this->admin('/reconciliation/export.csv'))->assertOk()->getContent();
    expect($csv)->toContain('"On the statement only",2027-03-06,P9,R0.30')->toContain('"In WURK only",2027-03-06,P5,"Glow Salon"')->toContain('"(no provider ID)"');
    $log = (string) json_encode(DB::table('audit_events')->where('action', 'billing.reconciliation')->first());   // the whole logged event
    expect($log)->not->toContain('082')->not->toContain('+27');                                                           // counts only
});

it('matches by number and day when a statement has no IDs, keeps nothing once cleared, and is for finance', function () {
    ($this->check)(['number' => 2, 'date' => 0, 'cost' => 3]);
    expect(($this->result)())->toMatchArray(['matchedBy' => 'number and day', 'matched' => 2, 'statementOnly' => 1, 'wurkOnly' => 2, 'unreadable' => 1]);
    ($this->check)([])->assertSessionHasErrors(['id' => 'Choose the message ID column, or both the number and date columns.']);
    $this->actingAs($this->finance)->post($this->admin('/reconciliation/clear'))->assertSessionHas('status', 'Cleared.');
    $this->actingAs($this->finance)->get($this->admin('/reconciliation'))->assertInertia(fn (Assert $p) => $p->where('upload', null)->where('result', null));
    $this->actingAs($this->staffWithRole('support', 'support@wurk.co.za'))->get($this->admin('/reconciliation'))->assertForbidden();
});
