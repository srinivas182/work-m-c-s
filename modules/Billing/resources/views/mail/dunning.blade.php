@if ($kind === 'suspended')
{{ $business }} is suspended because the subscription hasn’t been paid. Your website shows a "temporarily unavailable" page and the back office only opens Billing.

Pay now and everything comes back immediately — nothing has been deleted:
{{ $payUrl }}
@else
We couldn’t take your WURK payment for {{ $business }}. Everything keeps working for now; we’ll try again automatically.

To avoid interruption, pay with another card or at a WURK office:
{{ $payUrl }}

From day {{ $restrictDay }} the back office opens only Billing, and on day {{ $suspendDay }} the business is suspended until it’s paid.
@endif
