@if ($kind === 'empty')
Your WURK credits for {{ $business }} have run out, so today's plan couldn't be paid. Everything keeps working for {{ $days }} more {{ $days === 1 ? 'day' : 'days' }}; after that the usual reminders start, and the business is paused until it's paid.

Top up any amount from R20 and everything carries on at once — nothing is ever deleted:
{{ $topUpUrl }}
@else
{{ $business }} has about {{ $days }} {{ $days === 1 ? 'day' : 'days' }} of WURK left on its credits. Your plan is paid day by day from them.

Top up any amount from R20, by Capitec Pay, SnapScan, EFT or card:
{{ $topUpUrl }}
@endif
