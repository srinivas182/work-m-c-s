<?php

declare(strict_types=1);

namespace Wurk\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\Membership;
use Wurk\Identity\Models\PlatformStaff;
use Wurk\Identity\Support\Destination;
use Wurk\Identity\Support\PasswordPolicy;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Bootstraps people from the command line: the first WURK staff account, or a
 * team member of a business (until invitations arrive).
 */
final class CreateIdentityCommand extends Command
{
    protected $signature = 'identities:create
        {email : Email address}
        {--first= : First name}
        {--last= : Last name}
        {--phone= : Mobile number}
        {--password= : Optional password (otherwise sign in with a code)}
        {--staff : Grant WURK staff access (admin back office)}
        {--tenant=* : Add as a team member of these business handles}';

    protected $description = 'Create a WURK ID (person) and optionally grant staff access or business membership';

    public function handle(TenantDirectory $directory): int
    {
        $email = Destination::parse((string) $this->argument('email'), 'email')->value;
        $phone = $this->option('phone') ? Destination::parse((string) $this->option('phone'), 'phone')->value : null;
        $password = $this->option('password');

        if (is_string($password)) {
            $validator = Validator::make(['password' => $password], ['password' => PasswordPolicy::rules()]);
            if ($validator->fails()) {
                $this->components->error($validator->errors()->first('password'));

                return self::FAILURE;
            }
        }

        $identity = Identity::query()->firstOrCreate(['email' => $email], [
            'first_name' => (string) ($this->option('first') ?: str($email)->before('@')->title()),
            'last_name' => (string) ($this->option('last') ?? ''),
            'phone' => $phone,
            'password' => $password,
        ]);

        if ($this->option('staff')) {
            PlatformStaff::query()->updateOrCreate(['identity_id' => $identity->id], ['status' => 'active']);
        }

        foreach ((array) $this->option('tenant') as $handle) {
            $tenant = $directory->findByHandle((string) $handle);
            if ($tenant === null) {
                $this->components->error("Business [{$handle}] not found.");

                return self::FAILURE;
            }
            Membership::query()->updateOrCreate(
                ['identity_id' => $identity->id, 'tenant_id' => $tenant->tenantId(), 'kind' => Membership::KIND_STAFF],
                ['status' => 'active'],
            );
        }

        $this->components->info("WURK ID ready for {$email}.");

        return self::SUCCESS;
    }
}
