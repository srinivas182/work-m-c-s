<?php

declare(strict_types=1);

namespace Wurk\Marketing\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Wurk\Marketing\Mail\ContactMessageMail;

/** A message to WURK from wurk.co.za: kept, and emailed to WURK's contact address (the contact form, and the sales assistant's hand-over). */
final class RecordContactMessage
{
    /** @param array{name: string, contact: string, business?: string|null, message: string} $data */
    public function __invoke(array $data, ?string $ip): void
    {
        $row = ['name' => $data['name'], 'contact' => $data['contact'], 'business' => $data['business'] ?? null, 'message' => $data['message']];
        DB::table('contact_messages')->insert([...$row, 'id' => (string) Str::ulid(), 'ip' => $ip, 'created_at' => now(), 'updated_at' => now()]);
        if (($to = config('wurk.contact_email')) !== null && $to !== '') {
            Mail::to((string) $to)->send(new ContactMessageMail($row));
        }
    }
}
