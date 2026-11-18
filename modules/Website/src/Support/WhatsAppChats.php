<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Crm\Contracts\Audiences;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Messaging\Events\WhatsAppReceived;
use Wurk\Messaging\Events\WhatsAppStatusChanged;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Jobs\AnswerOnWhatsApp;
use Wurk\Website\Jobs\FetchWhatsAppMedia;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Models\ReceptionistMessage;
use Wurk\Website\Support\Uploads\VirusScanner;

/**
 * WhatsApp conversations in Customers → Chats (Sprint 21.2): customers' messages to the business's own number, the team's
 * replies, files both ways, and WhatsApp's 24-hour window for free-form replies. They are receptionist conversations
 * with channel "whatsapp", so the AI receptionist can answer them too (21.3).
 */
final readonly class WhatsAppChats
{
    public const CHANNEL = 'whatsapp';

    /** WhatsApp's customer service window: free-form replies only within 24 hours of the customer's last message. */
    public const WINDOW_HOURS = 24;

    /** Files the team may send: what WhatsApp accepts as photos and documents (up to 16 MB). */
    public const SEND_TYPES = ['image/jpeg' => 'image', 'image/png' => 'image', 'application/pdf' => 'document'];

    /** "STOP" and its everyday equivalents opt the customer out of marketing messages. */
    private const STOP = ['stop', 'unsubscribe', 'yima', 'misa', 'stop asseblief', 'hamba', 'acha', 'arrête'];

    public function __construct(private TenantContext $context, private ContactBook $contacts, private Audiences $audiences, private AuditLog $audit) {}

    public function received(WhatsAppReceived $e): ?ReceptionistConversation
    {
        if (ReceptionistMessage::query()->where('external_id', $e->messageId)->exists()) {
            return null; // Meta delivers some messages more than once
        }
        $phone = '+'.$e->from;
        $conversation = ReceptionistConversation::query()->where('channel', self::CHANNEL)->where('phone', $phone)
            ->where('status', '!=', 'closed')->latest('last_message_at')->first();
        if ($conversation === null) {
            $contact = $this->contacts->find($phone);
            $conversation = ReceptionistConversation::query()->create([
                // With the assistant on for WhatsApp it answers first (21.3); otherwise the chat waits for the team.
                'channel' => self::CHANNEL, 'visitor' => hash('sha256', 'wa:'.$e->from), 'status' => app(WhatsAppReceptionist::class)->on() ? 'ai' : 'waiting',
                'name' => $contact['name'] ?? ($e->name !== null ? mb_substr($e->name, 0, 80) : null), 'phone' => $phone,
                // Kept like the chats in the WhatsApp app (not swept after 30 days like the website assistant's); voice notes' audio still expires.
                'contact_id' => $contact['id'] ?? null, 'last_message_at' => now(), 'keep' => true,
            ]);
            if ($conversation->contact_id === null) {
                $id = $this->contacts->remember($conversation->name ?? $phone, $phone, null, 'whatsapp_chat', $conversation->id, null, 'WhatsApp');
                $conversation->update(['contact_id' => $id]);
            }
        }
        $text = $e->text !== '' ? $e->text : match ($e->type) {
            'image' => '[Photo]', 'video' => '[Video]', 'document' => '[Document'.($e->filename ? ': '.$e->filename : '').']',
            'audio' => '[Voice note]', 'sticker' => '[Sticker]', 'location' => '[Location]', 'contacts' => '[Contact card]',
            default => '[Message WhatsApp can’t show here]',
        };
        $message = $this->append($conversation, 'user', $text, $e->messageId);
        $conversation->update(['last_message_at' => now(), 'last_inbound_at' => $e->sentAt]);
        if ($e->mediaId !== null) {
            FetchWhatsAppMedia::dispatch($this->context->id(), $message->id, $e->mediaId, $e->mime, $e->filename);
        }
        if (in_array(mb_strtolower(trim($e->text)), self::STOP, true) && $conversation->contact_id !== null) {
            $this->audiences->withdrawConsent($conversation->contact_id, 'Replied "'.mb_substr(trim($e->text), 0, 20).'" on WhatsApp');
            $this->append($conversation, 'person', 'You won’t get marketing messages from us any more. You can still message us any time.', null);
            app(WhatsAppBusiness::class)->sendText($phone, 'You won’t get marketing messages from us any more. You can still message us any time.');
            $this->audit->record('website.whatsapp_opt_out', 'receptionist_conversation', $conversation->id, []);
        } elseif ($conversation->status === 'ai' && ($e->type !== 'audio' || $e->mediaId === null)) { // voice notes are answered once transcribed (21.4)
            AnswerOnWhatsApp::dispatch($this->context->id(), $conversation->id, $message->id)->delay(now()->addSeconds(AnswerOnWhatsApp::SETTLE));
        }

        return $conversation;
    }

    public function status(WhatsAppStatusChanged $e): void
    {
        $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        $m = ReceptionistMessage::query()->where('external_id', $e->messageId)->first();
        // Meta's statuses can arrive out of order: never step back from "read" to "delivered".
        if ($m !== null && ($rank[$e->status] ?? 0) > ($rank[$m->delivery] ?? 0)) {
            $m->update(['delivery' => $e->status, 'delivery_error' => $e->status === 'failed' ? mb_substr((string) $e->error, 0, 300) : null]);
        }
    }

    /** The assistant answers this message now, if it has the chat and nothing newer came in (voice notes — 21.4). */
    public function answerLater(ReceptionistMessage $message): void
    {
        $c = ReceptionistConversation::query()->find($message->conversation_id);
        $latest = ReceptionistMessage::query()->where('conversation_id', $message->conversation_id)->where('role', 'user')->orderByDesc('position')->value('id');
        if ($c !== null && $c->status === 'ai' && $latest === $message->id) {
            AnswerOnWhatsApp::dispatch($this->context->id(), $c->id, $message->id);
        }
    }

    /** Whether free-form replies are allowed now (within 24 hours of the customer's last message). */
    public static function windowOpen(ReceptionistConversation $c): bool
    {
        return $c->last_inbound_at !== null && $c->last_inbound_at->gt(now()->subHours(self::WINDOW_HOURS));
    }

    /** A reply from the business's team: text, a photo or a document. The team now has the chat. */
    public function reply(ReceptionistConversation $c, string $text, ?UploadedFile $file, ?string $by, WhatsAppBusiness $whatsapp, VirusScanner $scanner): ReceptionistMessage
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['text' => $m]);
        if (! self::windowOpen($c)) {
            $fail('More than 24 hours have passed since '.($c->name ?? 'the customer').' last wrote, so WhatsApp only allows an approved message. Send your follow-up, or call them.');
        }
        $attachment = null;
        if ($file !== null) {
            $mime = (string) $file->getMimeType();
            $kind = self::SEND_TYPES[$mime] ?? $fail('Send a JPEG or PNG photo, or a PDF.');
            $contents = (string) file_get_contents((string) $file->getRealPath());
            if ($scanner->scan($contents) !== VirusScanner::CLEAN) {
                $fail('That file can’t be sent: it didn’t pass the virus check.');
            }
            $name = mb_substr($file->getClientOriginalName() ?: 'file', 0, 120);
            $result = $whatsapp->sendMedia((string) $c->phone, $kind, $contents, $mime, $name, $text);
            $attachment = ['path' => $this->keep($contents, $mime), 'mime' => $mime, 'name' => $name, 'bytes' => strlen($contents), 'kind' => $kind];
        } else {
            $result = $whatsapp->sendText((string) $c->phone, $text);
        }
        if (! $result->ok) {
            $fail((string) $result->error);
        }
        $message = $this->append($c, 'person', $text !== '' ? $text : '['.(($attachment['kind'] ?? '') === 'image' ? 'Photo' : 'Document').']', $result->providerId, 'sent');
        if ($attachment !== null) {
            $message->update(['attachment' => $attachment]);
        }
        $c->update(['status' => 'person', 'keep' => true, 'last_message_at' => now()]);
        $this->audit->record('website.whatsapp_replied', 'receptionist_conversation', $c->id, ['by' => $by, 'file' => $attachment !== null]);

        return $message;
    }

    /**
     * After the 24-hour window, the business's approved follow-up template. Once the customer replies, the window opens
     * again and the team can write freely.
     */
    public function followUp(ReceptionistConversation $c, ?string $by, WhatsAppBusiness $whatsapp): ReceptionistMessage
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['text' => $m]);
        if (self::windowOpen($c)) {
            $fail('The 24-hour window is still open: write your reply instead.');
        }
        if (ReceptionistMessage::query()->where('conversation_id', $c->id)->where('created_at', '>', $c->last_inbound_at ?? now()->subYears(10))
            ->where('role', 'person')->where('content->follow_up', true)->exists()) {
            $fail('You’ve already followed up. Wait for '.($c->name ?? 'them').' to reply.'); // one nudge per silence, so it never turns into spam
        }
        $result = $whatsapp->sendFollowUp((string) $c->phone, (string) $c->name);
        if (! $result->ok) {
            $fail((string) $result->error);
        }
        $message = $this->append($c, 'person', $whatsapp->followUpText((string) $c->name), $result->providerId, 'sent', ['follow_up' => true]);
        $c->update(['status' => 'person', 'keep' => true, 'last_message_at' => now()]);
        $this->audit->record('website.whatsapp_followed_up', 'receptionist_conversation', $c->id, ['by' => $by]);

        return $message;
    }

    /** Stores a file privately under the business (customers' media and the team's sends). */
    public function keep(string $contents, string $mime): string
    {
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf', 'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'video/mp4' => 'mp4'][strtok($mime, ';') ?: ''] ?? 'bin';
        $path = 'whatsapp/'.$this->context->id().'/'.bin2hex(random_bytes(16)).'.'.$ext;
        // The same private, encrypted store as files visitors attach to forms.
        Storage::disk('form-files')->put($path, Crypt::encrypt($contents, false));

        return $path;
    }

    /** A stored file's contents (null when it's gone). */
    public static function read(string $path): ?string
    {
        $stored = Storage::disk('form-files')->get($path);

        return $stored === null ? null : (string) Crypt::decrypt($stored, false);
    }

    /** @param  array<string, mixed>  $extra */
    private function append(ReceptionistConversation $c, string $role, string $text, ?string $externalId, ?string $delivery = null, array $extra = []): ReceptionistMessage
    {
        return DB::connection($this->context->connectionName())->transaction(function () use ($c, $role, $text, $externalId, $delivery, $extra) {
            $position = (int) ReceptionistMessage::query()->where('conversation_id', $c->id)->lockForUpdate()->max('position') + 1;

            return ReceptionistMessage::query()->create(['conversation_id' => $c->id, 'position' => $position, 'role' => $role,
                'content' => ['role' => $role === 'person' ? 'assistant' : 'user', 'content' => mb_substr($text, 0, 4096)] + ($role === 'person' ? ['person' => true] : []) + $extra,
                'external_id' => $externalId, 'delivery' => $delivery, 'created_at' => now()]);
        });
    }
}
