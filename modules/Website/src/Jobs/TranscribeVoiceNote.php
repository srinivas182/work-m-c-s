<?php

declare(strict_types=1);

namespace Wurk\Website\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Wurk\Ai\Contracts\Ai;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Credits\Contracts\SpendingLimitReached;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\ReceptionistMessage;
use Wurk\Website\Support\VoiceNotes;
use Wurk\Website\Support\WhatsAppChats;

/**
 * Transcribes a customer's voice note (Sprint 21.4) with WURK AI's speech-to-text, charged in credits (refunded when it
 * fails). The words replace "[Voice note]" in the chat, so the team reads them and the assistant answers them as if
 * typed; when WURK AI isn't sure, the words are marked unclear and the assistant reads them back to confirm.
 */
final class TranscribeVoiceNote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CHANNEL = 'ai.transcribe';

    public function __construct(public readonly string $tenantId, public readonly string $messageId) {}

    public function handle(TenantDirectory $tenants, TenantContext $context): void
    {
        $tenant = $tenants->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        $context->run($tenant, function () use ($context): void {
            $message = ReceptionistMessage::query()->find($this->messageId);
            $path = $message?->attachment['path'] ?? null;
            if ($message === null || ! is_string($path) || isset($message->content['voice'])) {
                return;
            }
            $audio = WhatsAppChats::read($path);
            if ($audio !== null) {
                $this->transcribe($message, $audio, $message->attachment['mime'] ?? 'audio/ogg', $context->id());
            }
            app(WhatsAppChats::class)->answerLater($message->refresh());
        });
    }

    private function transcribe(ReceptionistMessage $message, string $audio, string $mime, string $tenantId): void
    {
        $seconds = VoiceNotes::seconds($audio, $mime);
        $attachment = [...(array) $message->attachment, 'seconds' => $seconds === null ? null : (int) round($seconds)];
        $caption = trim((string) ($message->content['content'] ?? ''));
        if (VoiceNotes::tooLong($audio, $seconds)) {
            $message->update(['attachment' => $attachment, 'content' => [...$message->content, 'voice' => ['too_long' => true],
                'content' => '[Voice note'.($seconds !== null ? ', '.(int) ceil($seconds / 60).' minutes' : '').' — too long to listen to]']]);

            return;
        }
        $wallet = app(CreditWallet::class);
        $key = 'transcribe:'.$message->id;
        try {
            $wallet->charge(self::CHANNEL, $key, 'AI: a voice note transcribed');
        } catch (InsufficientCredits|SpendingLimitReached) {
            $message->update(['attachment' => $attachment]); // stays "[Voice note]": the team can play it

            return;
        }
        try {
            $heard = app(Ai::class)->transcribe($audio, $mime, $seconds, $tenantId);
        } catch (AiUnavailable) {
            $wallet->refund($key, 'Refund: voice note not transcribed');
            $message->update(['attachment' => $attachment]);

            return;
        }
        $unclear = $heard->unclear();
        $text = trim($heard->text) === '' ? '[Voice note — nothing could be made out]' : ($unclear ? '[Voice note, unclear] ' : '').mb_substr(trim($heard->text), 0, 4000);
        $message->update(['attachment' => $attachment, 'content' => [...$message->content, 'content' => $text, 'voice' => [
            'language' => $heard->language, 'confidence' => $heard->confidence === null ? null : round($heard->confidence, 2), 'unclear' => $unclear,
        ]] + ($caption !== '' && $caption !== '[Voice note]' ? ['caption' => $caption] : [])]);
    }
}
