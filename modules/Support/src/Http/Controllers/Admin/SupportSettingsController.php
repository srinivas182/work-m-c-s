<?php

declare(strict_types=1);

namespace Wurk\Support\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Support\Models\SupportCannedReply;
use Wurk\Support\Support\SupportTargets;

/** Support inbox settings (Sprint 16.3b): first-reply targets, business hours, the inbox email, canned replies. */
final class SupportSettingsController
{
    public function __construct(private readonly SupportTargets $targets) {}

    public function show(): Response
    {
        return Inertia::render('Support::Admin/Settings', [
            'settings' => $this->targets->all(),
            'fallbackEmail' => config('wurk.support_email'),
            'canned' => SupportCannedReply::query()->orderBy('title')->get(['id', 'title', 'body', 'uses'])->toArray(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'targets' => ['required', 'array'],
            'targets.*' => ['required', 'integer', 'between:1,168'],
            'managed' => ['required', 'integer', 'between:1,168'],
            'other' => ['required', 'integer', 'between:1,168'],
            'business_hours.start' => ['required', 'integer', 'between:0,23'],
            'business_hours.end' => ['required', 'integer', 'between:1,24', 'gt:business_hours.start'],
            'business_hours.days' => ['required', 'array', 'min:1'],
            'business_hours.days.*' => ['integer', 'between:1,7'],
            'inbox_email' => ['nullable', 'email', 'max:160'],
        ], ['business_hours.end.gt' => 'Closing must be after opening.']);
        $d['business_hours']['tz'] = $this->targets->all()['business_hours']['tz'];
        $d['business_hours']['days'] = array_values(array_unique(array_map('intval', $d['business_hours']['days'])));
        $this->targets->save($d, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Support settings saved. New tickets use them; open tickets keep their targets.');
    }

    public function saveCanned(Request $request, ?string $reply = null): RedirectResponse
    {
        $row = $reply === null ? new SupportCannedReply : SupportCannedReply::query()->findOrFail($reply);
        $d = $request->validate([
            'title' => ['required', 'string', 'max:80', 'unique:support_canned_replies,title'.($row->exists ? ','.$row->id : '')],
            'body' => ['required', 'string', 'max:5000'],
        ], ['title.unique' => 'You already have a canned reply with that title.']);
        $row->fill([...$d, 'updated_by' => (string) $request->user()?->getAuthIdentifier()])->save();

        return back()->with('status', 'Canned reply saved.');
    }

    public function deleteCanned(string $reply): RedirectResponse
    {
        SupportCannedReply::query()->whereKey($reply)->delete();

        return back()->with('status', 'Canned reply deleted.');
    }
}
