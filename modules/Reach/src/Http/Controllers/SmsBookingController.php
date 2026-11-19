<?php

declare(strict_types=1);

namespace Wurk\Reach\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Reach\Models\ReachNumber;
use Wurk\Reach\Models\UssdSession;
use Wurk\Reach\Support\AfricasTalkingSms;
use Wurk\Reach\Ussd\UssdMenu;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * SMS booking (25.2b): a customer texts BOOK and the business's number (or its own word, e.g. GLOW) to WURK's SMS
 * number, and is answered by SMS with the same menu as USSD — one reply per step, choices by number. A conversation
 * lasts 30 minutes from the last message. Replies are charged to the business as SMS; a texted keyword that matches
 * no business gets one free reply.
 */
final class SmsBookingController
{
    public const IDLE_MINUTES = 30;

    public function __invoke(Request $request, string $token, ProviderCredentials $credentials, UssdMenu $menu, AfricasTalkingSms $sms,
        TenantContext $context, TenantDirectory $tenants): Response
    {
        $active = $credentials->active('ussd');
        $expected = (string) ($active['settings']['webhook_token'] ?? '');
        if (($active['driver'] ?? null) !== 'africastalking' || strlen($expected) < 16 || ! hash_equals($expected, $token) || ($active['settings']['sms_number'] ?? '') === '') {
            abort(404);
        }
        $from = (string) $request->input('from', '');
        $text = trim((string) $request->input('text', ''));
        if (preg_match('/^\+?\d{8,15}$/', $from) !== 1 || $text === '' || mb_strlen($text) > 160) {
            return response('', 200);
        }
        $phone = '+'.ltrim($from, '+');

        Cache::lock('sms-booking:'.$phone, 10)->block(5, function () use ($phone, $text, $menu, $sms, $active, $context, $tenants) {
            $start = $this->start($text);
            $session = $start !== null ? null : UssdSession::query()->where('provider', 'sms')->where('phone', $phone)->where('ended', false)
                ->where('updated_at', '>=', now()->subMinutes(self::IDLE_MINUTES))->orderByDesc('updated_at')->first();
            $first = $session === null;
            if ($first && $start === null) { // no conversation open: a known number goes straight there, anything else asks
                $start = ctype_digit($text) && ReachNumber::query()->where('number', $text)->exists() ? $text : '';
            }
            $session ??= new UssdSession(['id' => 'sms:'.Str::ulid(), 'provider' => 'sms', 'phone' => $phone, 'state' => [], 'steps' => 0]);
            $screen = $menu->handle($session, $first ? (string) $start : $text, $first, 'sms');
            $session->steps++;
            if ($screen->end || $session->steps >= UssdController::MAX_STEPS) {
                $session->ended = true;
            }
            $session->save();

            $text = $screen->render();
            $reply = $text.(! $screen->end && preg_match('/\n\d /', $text) === 1 ? "\nReply with a number." : '');
            $tenant = $session->tenant_id === null ? null : $tenants->find($session->tenant_id);
            if ($tenant === null) {
                $sms->send($phone, $reply, $active['credentials'], $active['settings']); // not yet a business: WURK's

                return;
            }
            $context->run($tenant, function () use ($sms, $phone, $reply, $active, $session) {
                try {
                    app(CreditWallet::class)->charge('sms', 'sms-booking:'.$session->id.':'.$session->steps, 'SMS booking reply');
                } catch (InsufficientCredits|\InvalidArgumentException) {
                    return; // out of credits: no reply (the business sees it in its credits)
                }
                $sms->send($phone, $reply, $active['credentials'], $active['settings']);
            });
        });

        return response('', 200);
    }

    /**
     * "BOOK" (then the business's number or word) or the business's own word always starts a new conversation.
     * Returns the business's number to start with, '' to ask for it, or null when the text is an answer.
     */
    private function start(string $text): ?string
    {
        $words = array_values(array_filter(preg_split('/\s+/', strtoupper(trim($text))) ?: []));
        $book = ($words[0] ?? '') === 'BOOK';
        if ($book) {
            array_shift($words);
        }
        $word = (string) ($words[0] ?? '');
        if (count($words) > 1) {
            return $book ? '' : null;
        }
        if ($word === '') {
            return $book ? '' : null;
        }
        if (ctype_digit($word)) {
            return $book ? $word : null;
        }
        $number = ReachNumber::query()->where('sms_keyword', $word)->value('number');

        return $number !== null ? (string) $number : ($book ? '' : null);
    }
}
