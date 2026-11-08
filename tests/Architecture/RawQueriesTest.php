<?php

declare(strict_types=1);

/*
 * Shards hold many businesses. Eloquent models filter by business automatically; raw queries
 * (DB::connection(...)->table('x')) don't. v0.14.1 and v0.14.2 fixed raw queries that read or deleted other
 * businesses' rows. So: a raw query on a business table must filter by tenant_id in the same statement — or be
 * listed below, reviewed, with the reason it's safe. New code must do one or the other.
 */

/** Reviewed (3 Nov 2026): these look rows up by records that already belong to the business, or are platform tools. */
const REVIEWED_RAW_QUERIES = [
    'Access/src/Console/SyncPermissionsCommand.php' => ['roles' => 'platform command over every business'],
    'Audit/src/Chain/Recorder.php' => ['audit_chain_heads' => 'keyed by scope = the business id', 'audit_events' => 'keyed by scope = the business id'],
    'Audit/src/Chain/Verifier.php' => ['audit_chain_heads' => 'keyed by scope', 'audit_events' => 'keyed by scope'],
    'Audit/src/Console/VerifyAuditCommand.php' => ['audit_chain_heads' => 'platform command over every scope'],
    'Bookings/src/Http/Controllers/BookingSettingsController.php' => ['booking_service_branch' => 'by the business’s service ids', 'booking_service_member' => 'by the business’s service ids'],
    'Bookings/src/Http/Controllers/PublicBookingController.php' => ['booking_service_member' => 'by the business’s service ids'],
    'Bookings/src/Support/Availability.php' => ['booking_service_member' => 'by service id', 'booking_slots' => 'by the business’s team-member id (per business)'],
    'Bookings/src/Support/Booker.php' => ['booking_service_member' => 'by service id', 'booking_slots' => 'by booking id'],
    'Bookings/src/Support/BookingMessages.php' => ['booking_messages' => 'by message id'],
    'Bookings/src/Support/BranchServices.php' => ['booking_service_branch' => 'by service id'],
    'Bookings/src/Support/ClassBooker.php' => ['class_sessions' => 'by session id'],
    'Bookings/src/Support/MeetingLinks.php' => ['booking_service_member' => 'by service id'],
    'Crm/src/Http/Controllers/ContactsController.php' => ['contact_links' => 'by contact id / the business’s branch ids'],
    'Crm/src/Http/Controllers/LeadsController.php' => ['lead_events' => 'by lead id'],
    'Crm/src/Support/Contacts.php' => ['contact_links' => 'by source record / contact ids'],
    'Records/src/Http/Controllers/RecordsController.php' => ['record_access_logs' => 'by patient id'],
    'Shop/src/Http/Controllers/OrdersController.php' => ['shop_order_events' => 'by order id'],
    'Shop/src/Support/BranchStock.php' => ['shop_stock' => 'by variant id', 'shop_variant_prices' => 'by variant id'],
    'Shop/src/Support/OrderMessages.php' => ['shop_order_events' => 'by order id'],
    'Team/src/Support/Settlements.php' => ['branch_settlements' => 'settling: by the business’s branch ids (the statement filters by tenant_id)'],
    'Website/src/Http/Controllers/PublicChatController.php' => ['chat_taps' => 'the key array includes tenant_id'],
];

it('filters raw queries on business tables by business, or they are on the reviewed list', function () {
    $root = dirname(__DIR__, 2);
    $tables = [];
    foreach (glob($root.'/modules/*/database/migrations/tenant/*.php') ?: [] as $migration) {
        preg_match_all("/Schema::create\\('([a-z_]+)'/", (string) file_get_contents($migration), $m);
        $tables = [...$tables, ...$m[1]];
    }
    $tables = array_flip($tables);
    $unscoped = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/modules', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = str_replace($root.'/modules/', '', $file->getPathname());
        if (! str_ends_with($path, '.php') || ! preg_match('#^[A-Za-z]+/src/#', $path)) {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        preg_match_all("/->table\\('([a-z_]+)'\\)/", $code, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[1] as [$table, $at]) {
            if (! isset($tables[$table])) {
                continue;
            }
            $statement = substr($code, $at, max(0, (int) strpos($code, ';', $at) - $at));
            if (! str_contains($statement, 'tenant_id') && ! isset(REVIEWED_RAW_QUERIES[$path][$table])) {
                $unscoped[] = $path.':'.(substr_count(substr($code, 0, $at), "\n") + 1).' '.$table;
            }
        }
    }
    expect(array_values(array_unique($unscoped)))->toBe([]);
});

it('keeps the reviewed list honest: every entry still exists', function () {
    $root = dirname(__DIR__, 2);
    $stale = [];
    foreach (REVIEWED_RAW_QUERIES as $path => $tables) {
        $code = (string) @file_get_contents($root.'/modules/'.$path);
        foreach (array_keys($tables) as $table) {
            if (! str_contains($code, "->table('{$table}')")) {
                $stale[] = $path.' '.$table;
            }
        }
    }
    expect($stale)->toBe([]);
});
