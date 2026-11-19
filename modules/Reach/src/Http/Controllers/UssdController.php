<?php

declare(strict_types=1);

namespace Wurk\Reach\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Reach\Models\UssdSession;
use Wurk\Reach\Ussd\AfricasTalkingUssd;
use Wurk\Reach\Ussd\Screen;
use Wurk\Reach\Ussd\UssdMenu;
use Wurk\Reach\Ussd\UssdProvider;

/**
 * WURK's USSD code (25.2): the gateway posts every step here. The address carries a secret token (set in
 * Admin → Providers → USSD), as gateways don't sign their requests; anything else is "not found".
 */
final class UssdController
{
    /** @var array<string, class-string<UssdProvider>> */
    public const PROVIDERS = ['africastalking' => AfricasTalkingUssd::class];

    /** A session that runs this long is ended (a stuck phone or a loop). */
    public const MAX_STEPS = 40;

    public function __invoke(Request $request, string $provider, string $token, ProviderCredentials $credentials, UssdMenu $menu): Response
    {
        $active = $credentials->active('ussd');
        $expected = (string) ($active['settings']['webhook_token'] ?? '');
        if (! isset(self::PROVIDERS[$provider]) || ($active['driver'] ?? null) !== $provider || strlen($expected) < 16 || ! hash_equals($expected, $token)) {
            abort(404);
        }
        $gateway = app(self::PROVIDERS[$provider]);
        $step = $gateway->parse($request) ?? abort(422);

        return Cache::lock('ussd:'.$step['session'], 10)->block(5, function () use ($gateway, $step, $menu, $provider): Response {
            $session = UssdSession::query()->find($step['session'])
                ?? new UssdSession(['id' => $step['session'], 'provider' => $provider, 'phone' => $step['phone'], 'state' => [], 'steps' => 0]);
            if ($session->ended || $session->steps >= self::MAX_STEPS) {
                $session->ended = true;
                $session->save();

                return $gateway->reply(Screen::end('This session has ended. Dial again to start over.'));
            }
            $screen = $menu->handle($session, $step['input'], $step['first'] || ! $session->exists);
            $session->state = [...$session->state, '_text' => $step['text']];
            $session->steps++;
            if ($screen->end) {
                $session->ended = true;
            }
            $session->save();

            return $gateway->reply($screen);
        });
    }
}
