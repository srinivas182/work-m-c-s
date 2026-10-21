<?php

declare(strict_types=1);

namespace Wurk\Security\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\IpUtils;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Security\Support\Settings;

final class SecurityController
{
    public function __construct(private readonly Settings $settings, private readonly AuditLog $audit) {}

    /** WURK staff: platform security settings. */
    public function platform(Request $request, PeopleDirectory $people): Response
    {
        Gate::authorize('security.manage');

        return Inertia::render('Security::Platform', [
            'allowlist' => (array) $this->settings->platform('admin_ip_allowlist', []),
            'yourIp' => $request->ip(),
            'cashOtpRecipients' => (array) $this->settings->platform('cash_otp_recipients', []),
            'cashReceiptRecipients' => (array) $this->settings->platform('cash_receipt_recipients', []),
            'staff' => array_values(array_map(fn (array $s) => ['id' => $s['id'], 'name' => $s['name']], array_filter($people->staff(), fn (array $s) => $s['active']))),
        ]);
    }

    public function updatePlatform(Request $request): RedirectResponse
    {
        Gate::authorize('security.manage');
        $data = $request->validate([
            'allowlist' => ['array', 'max:50'],
            'allowlist.*' => ['string', 'max:64'],
            'cash_otp_recipients' => ['array', 'max:10'],
            'cash_otp_recipients.*' => ['string', 'exists:platform_staff,identity_id'],
            'cash_receipt_recipients' => ['array', 'max:20'],
            'cash_receipt_recipients.*' => ['email'],
        ]);

        $allowlist = array_values(array_filter(array_map('trim', $data['allowlist'] ?? [])));
        foreach ($allowlist as $i => $entry) {
            if (! self::validCidr($entry)) {
                throw ValidationException::withMessages(["allowlist.{$i}" => "“{$entry}” isn’t an IP address or range (e.g. 196.25.10.0/24)."]);
            }
        }
        // Never let someone lock themselves (and everyone) out.
        if ($allowlist !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowlist)) {
            throw ValidationException::withMessages(['allowlist' => "Your current address ({$request->ip()}) isn’t in the list, so saving it would lock you out."]);
        }

        $by = (string) $request->user()?->getAuthIdentifier();
        $before = ['allowlist' => $this->settings->platform('admin_ip_allowlist', []), 'cash_otp_recipients' => $this->settings->platform('cash_otp_recipients', []), 'cash_receipt_recipients' => $this->settings->platform('cash_receipt_recipients', [])];
        $this->settings->setPlatform('admin_ip_allowlist', $allowlist, $by);
        $this->settings->setPlatform('cash_otp_recipients', array_values($data['cash_otp_recipients'] ?? []), $by);
        $this->settings->setPlatform('cash_receipt_recipients', array_values($data['cash_receipt_recipients'] ?? []), $by);
        $this->audit->record('security.platform_settings_changed', 'platform_settings', 'security', ['before' => $before, 'after' => [
            'allowlist' => $allowlist, 'cash_otp_recipients' => $data['cash_otp_recipients'] ?? [], 'cash_receipt_recipients' => $data['cash_receipt_recipients'] ?? [],
        ]], ['platform' => true]);

        return back()->with('status', 'Security settings saved.');
    }

    /** Business owners: team security. */
    public function business(): Response
    {
        return Inertia::render('Security::Business', ['requireMfa' => $this->settings->business('require_mfa', false) === true]);
    }

    public function updateBusiness(Request $request, PeopleDirectory $people): RedirectResponse
    {
        $data = $request->validate(['require_mfa' => ['required', 'boolean']]);
        $require = (bool) $data['require_mfa'];

        if ($require && ! $people->hasMfa((string) $request->user()?->getAuthIdentifier())) {
            throw ValidationException::withMessages(['require_mfa' => 'Set up your own authenticator app first, so you aren’t locked out.']);
        }

        $this->settings->setBusiness('require_mfa', $require);
        $this->audit->record($require ? 'security.team_mfa_required' : 'security.team_mfa_optional', 'business_settings', 'require_mfa');

        return back()->with('status', $require ? 'Everyone on the team now needs an authenticator app.' : 'Authenticator apps are optional again.');
    }

    private static function validCidr(string $value): bool
    {
        [$ip, $bits] = array_pad(explode('/', $value, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $max = str_contains((string) $ip, ':') ? 128 : 32;

        return $bits === null || (ctype_digit($bits) && (int) $bits >= 8 && (int) $bits <= $max);
    }
}
