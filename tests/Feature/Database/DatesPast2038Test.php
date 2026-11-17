<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithShards;
use Wurk\Platform\Database\DatesPast2038;
use Wurk\Platform\Database\DatesPast2038Grammar;
use Wurk\Tenancy\Sharding\ShardConnections;

uses(InteractsWithShards::class);

/** @return list<string> */
function timestampColumns(string $connection): array
{
    return array_map(fn ($c) => $c->t.'.'.$c->c, DB::connection($connection)->select(
        "select TABLE_NAME as t, COLUMN_NAME as c from information_schema.COLUMNS where TABLE_SCHEMA = database() and DATA_TYPE = 'timestamp'"));
}

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL only: SQLite has no TIMESTAMP type.');
    }
});

it('creates timestamp() columns as DATETIME on every MySQL connection', function () {
    [$shard] = $this->createShards(1);
    $connection = app(ShardConnections::class)->register($shard);
    // The schema grammar is made when the schema builder is first used on a connection.
    expect(DB::connection()->getSchemaBuilder()->getConnection()->getSchemaGrammar())->toBeInstanceOf(DatesPast2038Grammar::class)
        ->and(DB::connection($connection)->getSchemaBuilder()->getConnection()->getSchemaGrammar())->toBeInstanceOf(DatesPast2038Grammar::class);
    Schema::dropIfExists('y2038_made');
    Schema::create('y2038_made', fn (Blueprint $t) => [$t->id(), $t->timestamps(), $t->softDeletes(), $t->timestamp('at', 6)->useCurrent()]);
    try {
        expect(collect(DB::select("select DATA_TYPE as t from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = 'y2038_made' and COLUMN_NAME <> 'id'"))->pluck('t')->unique()->values()->all())
            ->toBe(['datetime']);
    } finally {
        Schema::dropIfExists('y2038_made');
    }
});

it('leaves no TIMESTAMP column in the central database or a shard, and stores dates after 2038', function () {
    [$shard] = $this->createShards(1);
    $connection = app(ShardConnections::class)->register($shard);
    expect(timestampColumns((string) config('database.default')))->toBe([])
        ->and(timestampColumns($connection))->toBe([]);

    $tenant = $this->createTenant('glow-salon', $shard, 'Glow Salon');
    DB::table('tenants')->where('id', $tenant->id)->update(['created_at' => '2045-06-01 09:30:00']);
    expect((string) DB::table('tenants')->where('id', $tenant->id)->value('created_at'))->toBe('2045-06-01 09:30:00');
});

it('keeps precision, nullability, defaults, ON UPDATE and comments when converting', function () {
    $db = DB::connection();
    $db->statement('drop table if exists y2038_probe');
    $db->statement("create table y2038_probe (id int primary key, a timestamp(6) not null default current_timestamp(6) on update current_timestamp(6) comment 'when',
        b timestamp null, c timestamp not null default '2020-01-01 00:00:00')");
    $db->table('y2038_probe')->insert(['id' => 1, 'b' => '2030-03-04 05:06:07']);
    try {
        expect(DatesPast2038::convert($db))->toBe(3);
        $cols = collect($db->select("select COLUMN_NAME as c, COLUMN_TYPE as type, IS_NULLABLE as n, COLUMN_DEFAULT as d, EXTRA as e, COLUMN_COMMENT as m
            from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = 'y2038_probe' and COLUMN_NAME <> 'id'"))->keyBy('c');
        expect($cols['a']->type)->toBe('datetime(6)')->and($cols['a']->n)->toBe('NO')->and(strtolower((string) $cols['a']->d))->toBe('current_timestamp(6)')
            ->and(strtolower((string) $cols['a']->e))->toContain('on update current_timestamp(6)')->and($cols['a']->m)->toBe('when')
            ->and($cols['b']->type)->toBe('datetime')->and($cols['b']->n)->toBe('YES')
            ->and($cols['c']->d)->toBe('2020-01-01 00:00:00')
            ->and((string) $db->table('y2038_probe')->value('b'))->toBe('2030-03-04 05:06:07');
    } finally {
        $db->statement('drop table if exists y2038_probe');
    }
});
