<?php

declare(strict_types=1);

namespace Wurk\Bookings\Receptionist;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Wurk\Bookings\Models\BookingService;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Shared by the booking tools of the AI receptionist (Sprint 19.2b).
 *
 * Changing a booking: the receptionist may move or cancel only bookings it made **in the same conversation**, or that
 * the customer proved are theirs with a code sent to the booking's phone or email (it remembers their ids for 30
 * days). Never just from a name or phone number, which anyone could know.
 */
final readonly class ChatBookings
{
    public function __construct(private TenantContext $context) {}

    public function service(string $idOrName): BookingService
    {
        $active = BookingService::query()->where('active', true);
        $service = (clone $active)->whereKey($idOrName)->first()
            ?? (clone $active)->whereRaw('lower(name) = ?', [mb_strtolower(trim($idOrName))])->first()
            ?? (clone $active)->whereRaw('lower(name) like ?', ['%'.mb_strtolower(trim($idOrName)).'%'])->first();

        return $service ?? throw new ReceptionistRefused('There is no service like that. Use the services tool to see what’s offered.');
    }

    /**
     * The customer's details, checked like the booking form: a name, a mobile number, consent to be contacted, and a
     * valid email when one is needed (payments email the receipt).
     *
     * @param  array<string, mixed>  $arguments
     * @return array{name: string, phone: string, email: string|null}
     */
    public function customer(array $arguments, bool $needsEmail = false, string $what = 'the booking'): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        $phone = trim((string) ($arguments['phone'] ?? ''));
        $email = trim((string) ($arguments['email'] ?? '')) ?: null;
        if ($name === '' || preg_match('/^[+0-9 ()-]{6,20}$/', $phone) !== 1) {
            throw new ReceptionistRefused('Ask for their name and a mobile number first.');
        }
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ReceptionistRefused('That email doesn’t look right — ask them to check it.');
        }
        if (($arguments['agreed_to_be_contacted'] ?? false) !== true) {
            throw new ReceptionistRefused("Ask whether they agree to be contacted about {$what} first.");
        }
        if ($needsEmail && $email === null) {
            throw new ReceptionistRefused('This is paid when booking and the receipt is emailed: ask for their email.');
        }

        return ['name' => mb_substr($name, 0, 120), 'phone' => $phone, 'email' => $email];
    }

    /**
     * The JSON-schema properties every booking tool asks for.
     *
     * @return array<string, array<string, string>>
     */
    public static function contactProperties(): array
    {
        return [
            'name' => ['type' => 'string'],
            'phone' => ['type' => 'string', 'description' => 'Mobile number'],
            'email' => ['type' => 'string', 'description' => 'Email (needed when it’s paid)'],
            'agreed_to_be_contacted' => ['type' => 'boolean', 'description' => 'True only if they agreed to be contacted about it'],
        ];
    }

    public function money(?int $cents): ?string
    {
        return $cents === null ? null : 'R'.number_format($cents / 100, 2, '.', ' ');
    }

    public function local(CarbonImmutable|\DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone($this->context->current()->timezone());
    }

    public function remember(string $conversation, string $kind, string $id): void
    {
        $key = $this->key($conversation);
        Cache::put($key, [...(array) Cache::get($key, []), "{$kind}:{$id}"], now()->addDays(30));
    }

    public function madeHere(string $conversation, string $kind, string $id): bool
    {
        return in_array("{$kind}:{$id}", (array) Cache::get($this->key($conversation), []), true);
    }

    public function returnUrl(): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$this->context->current()->handle()}.".config('wurk.domains.root').'/book/return';
    }

    private function key(string $conversation): string
    {
        return 'receptionist:made:'.$this->context->id().':'.$conversation;
    }
}
