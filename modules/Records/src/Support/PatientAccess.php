<?php

declare(strict_types=1);

namespace Wurk\Records\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Wurk\Identity\Contracts\TextMessenger;
use Wurk\Records\Mail\RecordCodeMail;
use Wurk\Records\Mail\RecordLinkMail;
use Wurk\Records\Models\Patient;
use Wurk\Records\Models\RecordLink;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * A patient's own access: a private link (only its hash stored), then a one-time code sent to them
 * (5 tries, 10 minutes; only a keyed hash stored), then a short session showing only their documents.
 */
final readonly class PatientAccess
{
    public function __construct(private TenantContext $context, private Records $records) {}

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function codeHash(string $code, string $tokenHash): string
    {
        return hash_hmac('sha256', $code.'|'.$tokenHash, (string) config('app.key'));
    }

    /** Send the patient their private link. */
    public function sendLink(Patient $patient, string $by): string
    {
        if ($patient->email === null && $patient->phone === null) {
            throw new \RuntimeException('Add the patient’s email or mobile number first.');
        }
        $token = Str::random(48);
        RecordLink::query()->create(['token_hash' => self::hash($token), 'patient_id' => $patient->id, 'expires_at' => now()->addDays((int) config('records.link_days', 14))]);
        $url = 'https://'.$this->context->current()->handle().'.'.config('wurk.domains.root').'/records/'.$token;
        $business = $this->context->current()->displayName();
        if ($patient->email !== null) {
            Mail::to($patient->email)->send(new RecordLinkMail($business, $patient->name, $url));
        } else {
            app(TextMessenger::class)->send((string) $patient->phone, "{$business}: your documents are ready. Open {$url} — you'll get a code to confirm it's you.", 'records');
        }
        $this->records->log($patient->id, null, 'link_sent', 'staff', $by);

        return $url;
    }

    /** The link, if it's still usable. */
    public function link(string $token): ?RecordLink
    {
        $link = RecordLink::query()->find(self::hash($token));

        return $link !== null && $link->expires_at->isFuture() ? $link : null;
    }

    /** Send a one-time code to the patient (replaces any earlier code). */
    public function sendCode(RecordLink $link, ?string $ip): void
    {
        $patient = Patient::query()->findOrFail($link->patient_id);
        $code = (string) random_int(100000, 999999);
        $link->update(['code_hash' => $this->codeHash($code, $link->token_hash), 'code_expires_at' => now()->addMinutes((int) config('records.code_minutes', 10))]);
        $business = $this->context->current()->displayName();
        try {
            if ($patient->email !== null) {
                Mail::to($patient->email)->send(new RecordCodeMail($business, $code));
            } else {
                app(TextMessenger::class)->send((string) $patient->phone, "{$business}: your code is {$code}. It expires in 10 minutes.", 'records');
            }
        } catch (Throwable) {
            // logged below either way; the patient can ask for another code
        }
        $this->records->log($patient->id, null, 'code_sent', 'patient', $patient->name, $ip);
    }

    /** Check a code: true on success. After too many tries the link is locked (a new one must be sent). */
    public function verify(RecordLink $link, string $code, ?string $ip): bool
    {
        $patient = Patient::query()->findOrFail($link->patient_id);
        $ok = $link->code_hash !== null && $link->code_expires_at?->isFuture() && hash_equals($link->code_hash, $this->codeHash(trim($code), $link->token_hash));
        if ($ok) {
            $link->update(['code_hash' => null, 'code_expires_at' => null, 'attempts' => 0]); // single use
            $this->records->log($patient->id, null, 'code_ok', 'patient', $patient->name, $ip);

            return true;
        }
        $attempts = $link->attempts + 1;
        $link->update(['attempts' => $attempts, ...($attempts >= (int) config('records.code_attempts', 5) ? ['expires_at' => now(), 'code_hash' => null] : [])]);
        $this->records->log($patient->id, null, 'code_failed', 'patient', $patient->name, $ip);

        return false;
    }

    /** The session cookie's value: encrypted; ties the browser to this link and patient for a short time. */
    public function session(RecordLink $link): string
    {
        return Crypt::encryptString((string) json_encode(['l' => $link->token_hash, 'p' => $link->patient_id, 'exp' => now()->addMinutes((int) config('records.session_minutes', 30))->getTimestamp()]));
    }

    /** The patient this browser has unlocked for this link, or null. */
    public function patientFor(RecordLink $link, ?string $cookie): ?Patient
    {
        try {
            $data = (array) json_decode(Crypt::decryptString((string) $cookie), true);
        } catch (Throwable) {
            return null;
        }
        if (($data['l'] ?? null) !== $link->token_hash || ($data['p'] ?? null) !== $link->patient_id || (int) ($data['exp'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return Patient::query()->find($link->patient_id);
    }
}
