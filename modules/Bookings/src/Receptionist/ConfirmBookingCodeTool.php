<?php

declare(strict_types=1);

namespace Wurk\Bookings\Receptionist;

use Illuminate\Support\Facades\Cache;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Tenancy\Contracts\TenantContext;

/** Check the code find_booking sent; the customer's upcoming bookings may then be moved or cancelled in this chat. */
final readonly class ConfirmBookingCodeTool implements ReceptionistTool
{
    private const TRIES = 5;

    public function __construct(private TenantContext $context, private ChatBookings $chat) {}

    public function name(): string
    {
        return 'confirm_booking_code';
    }

    public function description(): string
    {
        return 'Check the 6-digit code the customer received after find_booking. Returns their upcoming bookings, which move_booking and cancel_booking can then change.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'description' => 'The 6 digits the customer typed']], 'required' => ['code']];
    }

    public function capability(): string
    {
        return 'change';
    }

    public function entitlement(): string
    {
        return 'bookings';
    }

    public function run(array $arguments, array $context): array
    {
        $key = self::key($this->context->id(), $context['conversation']);
        $pending = Cache::get($key);
        if (! is_array($pending)) {
            throw new ReceptionistRefused('There’s no code waiting (it may have expired after 10 minutes). Use find_booking to send a new one.');
        }
        $code = (string) preg_replace('/\D/', '', (string) ($arguments['code'] ?? ''));
        if (! hash_equals((string) $pending['hash'], hash_hmac('sha256', $code, (string) config('app.key')))) {
            $pending['tries'] = (int) $pending['tries'] + 1;
            $pending['tries'] >= self::TRIES ? Cache::forget($key) : Cache::put($key, $pending, now()->addMinutes(10));
            throw new ReceptionistRefused($pending['tries'] >= self::TRIES ? 'Too many wrong codes. The customer can use the link in their confirmation message.' : 'That code isn’t right. Ask the customer to check it.');
        }
        Cache::forget($key);

        $bookings = Booking::query()->whereKey((array) $pending['bookings'])->whereIn('status', Booking::ACTIVE)->where('starts_at', '>', now())->orderBy('starts_at')->get();
        $names = BookingService::withTrashed()->whereKey($bookings->pluck('service_id'))->pluck('name', 'id');

        return ['verified' => true, 'bookings' => $bookings->map(function (Booking $b) use ($context, $names) {
            $this->chat->remember($context['conversation'], 'booking', (string) $b->id);

            return ['booking_id' => $b->id, 'service' => $names[$b->service_id] ?? 'Appointment', 'when' => $this->chat->local($b->starts_at)->format('l j F, H:i')];
        })->values()->all()];
    }

    public static function key(string $tenant, string $conversation): string
    {
        return "receptionist:code:{$tenant}:{$conversation}";
    }
}
