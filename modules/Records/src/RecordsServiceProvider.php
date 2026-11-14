<?php

declare(strict_types=1);

namespace Wurk\Records;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;
use Wurk\Platform\Contracts\DataExports;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Records\Console\FlagDueCommand;
use Wurk\Records\Models\Document;
use Wurk\Records\Support\Records;
use Wurk\Tenancy\Contracts\TenantContext;

final class RecordsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->mergeConfigFrom($this->modulePath('config/records.php'), 'records');
    }

    public function permissions(): array
    {
        return [
            ['key' => 'records.view', 'label' => 'See and add patient documents'],
            ['key' => 'records.manage', 'label' => 'Remove patient records after the retention period'],
        ];
    }

    public function menu(): array
    {
        return [
            ['label' => 'Patient records', 'route' => 'tenant.records', 'icon' => 'file-lock', 'section' => 'Bookings', 'permission' => 'records.view', 'entitlement' => 'bookings', 'order' => 260],
        ];
    }

    protected function bootModule(): void
    {
        // Your data (16.6): what this module keeps, for the full export.
        $this->callAfterResolving(DataExports::class, function (DataExports $x): void {
            $rows = fn (string $table, string $order) => fn () => DB::connection(app(TenantContext::class)->connectionName())->table($table)
                ->where('tenant_id', app(TenantContext::class)->id())->orderBy($order)->lazy(500)->map(fn ($r) => (array) $r);
            $x->table('patients', 'Patients (health records)', 'records.view', $rows('record_patients', 'created_at'));
            $x->table('patient-documents', 'Patient documents (list)', 'records.view', $rows('record_documents', 'created_at'));
            // The documents themselves, decrypted for the export — each read logged as for any other access (records.view only).
            $x->files('patient-documents', 'Patient documents', 'records.view', fn () => Document::query()->orderBy('created_at')->lazy(100)->map(fn (Document $d) => [
                'path' => $d->patient_id.'/'.$d->id.'-'.Str::slug($d->title).'.'.(MimeTypes::getDefault()->getExtensions($d->mime)[0] ?? 'bin'),
                'contents' => fn () => app(Records::class)->read($d, 'export', 'system', null),
            ]));
        });
        $this->loadViewsFrom($this->modulePath('resources/views'), 'records');
        // Per business, link and visitor — never one counter per IP across every business (shared mobile IPs).
        $key = fn (Request $r, string $name) => $name.'|'.(string) $r->route('handle').'|'.substr(hash('sha256', (string) $r->route('token')), 0, 16).'|'.$r->ip();
        RateLimiter::for('records-read', fn (Request $r) => Limit::perMinute(60)->by($key($r, 'records-read')));
        RateLimiter::for('records-code', fn (Request $r) => Limit::perMinutes(10, 5)->by($key($r, 'records-code')));
        RateLimiter::for('records-verify', fn (Request $r) => Limit::perMinutes(10, 10)->by($key($r, 'records-verify')));
        if ($this->app->runningInConsole()) {
            $this->commands([FlagDueCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('records:flag-due')->dailyAt('02:30')->onOneServer()->withoutOverlapping();
        });
    }
}
