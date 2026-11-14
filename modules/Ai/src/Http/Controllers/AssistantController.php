<?php

declare(strict_types=1);

namespace Wurk\Ai\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wurk\Ai\Support\Assistant;
use Wurk\Platform\Contracts\AssistantRefused;

/** The "Ask WURK" side panel (Sprint 16.2) in a business's back office. JSON only. */
final class AssistantController
{
    public function __construct(private readonly Assistant $assistant) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return new JsonResponse(['usage' => $this->assistant->usage(), 'history' => $this->assistant->history($user), 'suggestions' => $this->assistant->suggestions($user)]);
    }

    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:500'],
            'screen' => ['nullable', 'string', 'max:100'],
            'accept_charge' => ['boolean'],
        ]);
        $result = $this->assistant->ask($this->user($request), (string) $data['question'], (string) ($data['screen'] ?? ''), (bool) ($data['accept_charge'] ?? false));

        return new JsonResponse($result, match ($result['status']) {
            'refused' => 422, 'failed' => 503, default => 200,
        });
    }

    public function confirm(Request $request, string $action): JsonResponse
    {
        return $this->attempt(fn () => $this->assistant->confirm($this->user($request), $action));
    }

    public function undo(Request $request, string $action): JsonResponse
    {
        return $this->attempt(fn () => $this->assistant->undo($this->user($request), $action));
    }

    public function cancel(Request $request, string $action): JsonResponse
    {
        $this->assistant->cancel($this->user($request), $action);

        return new JsonResponse(['status' => 'cancelled']);
    }

    /** @param \Closure(): array<string, mixed> $work */
    private function attempt(\Closure $work): JsonResponse
    {
        try {
            return new JsonResponse($work());
        } catch (AssistantRefused $e) {
            return new JsonResponse(['status' => 'refused', 'message' => $e->getMessage()], 422);
        }
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return $user;
    }
}
