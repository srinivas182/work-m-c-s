<?php

declare(strict_types=1);

namespace Wurk\Ai\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Ai\Models\SalesConversation;
use Wurk\Ai\Support\SalesAssistant;

/** WURK's sales assistant on wurk.co.za (Sprint 19.5): whether it's on, and a visitor's message. */
final class SalesChatController
{
    /** Messages a day in all, a backstop against abuse (HQ's monthly cap is the real limit). */
    private const DAILY_CAP = 5000;

    public function config(SalesAssistant $sales): JsonResponse
    {
        $s = $sales->settings();

        return new JsonResponse($s['enabled'] ? ['enabled' => true, 'greeting' => $s['greeting'],
            'notice' => 'You’re chatting with WURK’s AI assistant. The conversation is kept for '.SalesConversation::KEEP_DAYS.' days.'] : ['enabled' => false]);
    }

    public function message(Request $request, SalesAssistant $sales): JsonResponse
    {
        $d = $request->validate([
            'visitor' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'message' => ['required', 'string', 'max:2000'],
            'conversation' => ['nullable', 'string', 'size:26'],
        ]);
        $key = 'sales-chat:'.now()->format('Y-m-d');
        abort_if(RateLimiter::tooManyAttempts($key, self::DAILY_CAP), 429);
        RateLimiter::hit($key, 86400);

        return new JsonResponse($sales->reply($d['visitor'], $d['message'], $d['conversation'] ?? null));
    }
}
