<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Wurk\Audit\Contracts\AuditLog;
use Wurk\Platform\Contracts\PushNotifications;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Tenancy\Events\CustomDomainChanged;

/**
 * Sprint 17.3: tells the business's website people (push, kind "website") when their domain starts working, stops
 * pointing at WURK (the site stays on its WURK address) or is let go because it never pointed at WURK. Audited.
 */
final readonly class DomainNotices
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private PushNotifications $push, private AuditLog $audit) {}

    public function changed(CustomDomainChanged $e): void
    {
        $tenant = $this->tenants->find($e->tenantId);
        if ($tenant === null) {
            return;
        }
        $this->context->run($tenant, function () use ($e, $tenant): void {
            $this->audit->record('website.domain_'.$e->status, 'domain', $e->host, []);
            [$title, $body] = match ($e->status) {
                'active' => [__('Your domain works'), __(':host now shows your website, with its own certificate.', ['host' => $e->host])],
                'lapsed' => [__('Your domain stopped working'), __(':host doesn’t point at WURK any more. Your site is still at :wurk.', ['host' => $e->host, 'wurk' => $tenant->handle().'.'.config('wurk.domains.root')])],
                default => [__('Your domain wasn’t connected'), __(':host never pointed at WURK, so we let it go. Add it again when you’re ready.', ['host' => $e->host])],
            };
            $this->push->notify('website', $title, $body, '/'.$tenant->handle().'/website/domain', 'website.publish');
        });
    }
}
