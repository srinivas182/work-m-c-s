<?php

declare(strict_types=1);

namespace Wurk\Referrals\Http\Controllers;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Referrals\Contracts\ReferralRules;
use Wurk\Referrals\Models\ReferralMember;
use Wurk\Tenancy\Contracts\TenantContext;

/** Customers → Referrals: each customer's code, link and QR code, who referred them and how many they've referred. */
final class ReferralMembersController
{
    public function index(Request $request, TenantContext $context, ContactBook $contacts, ReferralRules $rules): Response
    {
        $code = mb_strtoupper(trim((string) $request->query('code', '')));
        $page = ReferralMember::query()->when($code !== '', fn ($q) => $q->where('code', 'like', $code.'%'))->orderByDesc('created_at')->paginate(50)->withQueryString();
        $rows = collect($page->items());
        $referrers = ReferralMember::query()->whereIn('id', $rows->pluck('referrer_id')->filter()->unique()->values())->get()->keyBy('id');
        $names = $contacts->names(array_values(array_map('strval', [...$rows->pluck('contact_id')->all(), ...$referrers->pluck('contact_id')->all()])));
        $counts = ReferralMember::query()->whereIn('referrer_id', $rows->pluck('id'))->selectRaw('referrer_id, count(*) as n')->groupBy('referrer_id')->pluck('n', 'referrer_id');
        $handle = $context->current()->handle();

        return Inertia::render('Referrals::Members', [
            'enabled' => $rules->rules()['enabled'],
            'code' => $code,
            'members' => $rows->map(fn (ReferralMember $m) => [
                'id' => $m->id, 'name' => $names[$m->contact_id] ?? 'Customer', 'code' => $m->code, 'link' => route('referrals.link', ['handle' => $handle, 'code' => $m->code]),
                'referred_by' => $m->referrer_id === null ? null : ($names[$referrers[$m->referrer_id]->contact_id ?? ''] ?? 'Customer'),
                'referred' => (int) ($counts[$m->id] ?? 0),
            ])->all(),
            'pages' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** A customer's referral link as a QR code (SVG), to print or share. */
    public function qr(string $member, TenantContext $context): HttpResponse
    {
        $m = ReferralMember::query()->whereKey($member)->firstOrFail();
        $svg = (new Writer(new ImageRenderer(new RendererStyle(320, 2), new SvgImageBackEnd)))
            ->writeString(route('referrals.link', ['handle' => $context->current()->handle(), 'code' => $m->code]));

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Content-Disposition' => 'attachment; filename="referral-'.$m->code.'.svg"', 'Cache-Control' => 'private, max-age=3600']);
    }
}
