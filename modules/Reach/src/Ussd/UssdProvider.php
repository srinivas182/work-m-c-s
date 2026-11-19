<?php

declare(strict_types=1);

namespace Wurk\Reach\Ussd;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A USSD gateway's wire format (25.2): what it sends for each step, and how it wants the screen back. */
interface UssdProvider
{
    public function name(): string;

    /**
     * The step: the session, the caller's number, their latest answer, whether it's the session's first step, and the
     * gateway's raw text (kept to find the next answer).
     *
     * @return array{session: string, phone: string, input: string, first: bool, text: string}|null null when the request isn't this provider's
     */
    public function parse(Request $request): ?array;

    public function reply(Screen $screen): Response;
}
