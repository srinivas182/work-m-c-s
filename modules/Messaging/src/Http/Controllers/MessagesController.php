<?php

declare(strict_types=1);

namespace Wurk\Messaging\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Mail\CustomerMessageMail;
use Wurk\Messaging\Support\Templates;

/** Settings → Messages: every customer message, its wording per channel, test sends, and optional messages on or off. */
final class MessagesController
{
    public function __construct(private Templates $templates, private AuditLog $audit) {}

    public function index(): Response
    {
        $rows = [];
        foreach ($this->templates->definitions() as $key => $def) {
            $channels = [];
            foreach ($def['channels'] ?? Templates::CHANNELS as $channel) {
                $now = $this->templates->current($key, $channel);
                $channels[] = ['channel' => $channel, ...$now, 'preview' => $this->templates->sample($key, $channel)];
            }
            $rows[] = ['key' => $key, 'group' => $def['group'], 'label' => $def['label'], 'placeholders' => $def['placeholders'], 'required' => $def['required'],
                'channels' => $channels, 'whatsapp' => (bool) ($def['whatsapp'] ?? false), 'optional' => (bool) ($def['optional'] ?? false),
                'enabled' => $this->templates->enabled($key)];
        }

        return Inertia::render('Messaging::Messages', ['messages' => $rows, 'smsMax' => Templates::SMS_MAX]);
    }

    public function save(Request $request, string $key, string $channel): RedirectResponse
    {
        $this->known($key);
        $d = $request->validate(['subject' => ['nullable', 'string', 'max:200'], 'text' => ['required', 'string', 'max:3000']]);
        $this->templates->save($key, $channel, $d['subject'] ?? null, $d['text'], $this->who($request));
        $this->audit->record('messages.reworded', 'message', $key, ['channel' => $channel]);

        return back()->with('status', 'Message saved.');
    }

    public function reset(string $key, string $channel): RedirectResponse
    {
        $this->known($key);
        $this->templates->reset($key, $channel);
        $this->audit->record('messages.reset', 'message', $key, ['channel' => $channel]);

        return back()->with('status', 'Back to the standard wording.');
    }

    public function enabled(Request $request, string $key): RedirectResponse
    {
        $def = $this->known($key);
        abort_unless($def['optional'] ?? false, 404);
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $this->templates->setEnabled($key, $on);
        $this->audit->record('messages.switched', 'message', $key, ['enabled' => $on]);

        return back()->with('status', $on ? 'Switched on.' : 'Switched off.');
    }

    /** Send the message, with sample details, to the owner's own email (free) or phone (SMS credits). */
    public function test(Request $request, string $key, string $channel, Messenger $messenger, PeopleDirectory $people): RedirectResponse
    {
        $def = $this->known($key);
        abort_unless(in_array($channel, $def['channels'] ?? Templates::CHANNELS, true), 404);
        $me = $people->contacts([(string) $request->user()?->getAuthIdentifier()])[(string) $request->user()?->getAuthIdentifier()] ?? null;
        $msg = $this->templates->sample($key, $channel);
        if ($channel === 'email') {
            if (empty($me['email'])) {
                throw ValidationException::withMessages(['test' => 'Add an email address to your profile to send yourself a test.']);
            }
            Mail::to($me['email'])->send(new CustomerMessageMail('[Test] '.$msg['subject'], $msg['text']));

            return back()->with('status', 'Test email sent to '.$me['email'].'.');
        }
        if (empty($me['phone'])) {
            throw ValidationException::withMessages(['test' => 'Add a mobile number to your profile to send yourself a test.']);
        }
        $result = $messenger->sms((string) $me['phone'], $msg['text'], 'test'); // paid from SMS credits, like any SMS (decision B)
        if (! $result->ok) {
            throw ValidationException::withMessages(['test' => 'The test SMS couldn’t be sent: '.($result->error ?? 'please try again.')]);
        }

        return back()->with('status', 'Test SMS sent to '.$me['phone'].'.');
    }

    /** @return array{group: string, label: string, placeholders: list<string>, required: list<string>, subject: string, text: string, sample: array<string, string>, whatsapp?: bool, optional?: bool, channels?: list<string>} */
    private function known(string $key): array
    {
        return $this->templates->definitions()[$key] ?? abort(404);
    }

    private function who(Request $request): string
    {
        return trim((string) data_get($request->user(), 'first_name').' '.(string) data_get($request->user(), 'last_name')) ?: 'Team';
    }
}
