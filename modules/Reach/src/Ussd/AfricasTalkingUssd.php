<?php

declare(strict_types=1);

namespace Wurk\Reach\Ussd;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Reach\Models\UssdSession;

/**
 * Africa's Talking USSD (25.2): each step POSTs sessionId, phoneNumber, serviceCode and `text` — every answer so far,
 * joined by "*" ("" on the first step). The reply is plain text starting "CON " (wait for an answer) or "END ".
 * The latest answer is what follows the text we saw last time.
 */
final class AfricasTalkingUssd implements UssdProvider
{
    public function name(): string
    {
        return 'africastalking';
    }

    public function parse(Request $request): ?array
    {
        $session = (string) $request->input('sessionId', '');
        $phone = (string) $request->input('phoneNumber', '');
        if ($session === '' || preg_match('/^\+?\d{8,15}$/', $phone) !== 1) {
            return null;
        }
        $text = (string) $request->input('text', '');
        $seen = UssdSession::query()->whereKey($session)->value('state');
        $before = is_array($seen) ? (string) ($seen['_text'] ?? '') : (is_string($seen) ? (string) (json_decode($seen, true)['_text'] ?? '') : null);
        if ($before === null) { // a new session: anything in `text` was dialled in the code (*384*1234*5678#)
            return ['session' => $session, 'phone' => '+'.ltrim($phone, '+'), 'input' => $text, 'first' => true, 'text' => $text];
        }
        $input = $before === '' ? $text : (str_starts_with($text, $before.'*') ? substr($text, strlen($before) + 1) : (string) last(explode('*', $text)));

        return ['session' => $session, 'phone' => '+'.ltrim($phone, '+'), 'input' => $input, 'first' => false, 'text' => $text];
    }

    public function reply(Screen $screen): Response
    {
        return response(($screen->end ? 'END ' : 'CON ').$screen->render(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
