{{ ['waiting_list' => 'Someone joined your waiting list on your website.', 'interest' => 'Someone registered their interest on your website.', 'enquiry' => 'New enquiry from your website.'][$enquiry['form'] ?? 'quote'] ?? 'New quote request from your website.' }}

Name: {{ $enquiry['name'] }}
@if (! empty($enquiry['phone']))Phone: {{ $enquiry['phone'] }}
@endif
@if (! empty($enquiry['email']))Email: {{ $enquiry['email'] }}
@endif
@if (! empty($enquiry['service']))Service: {{ $enquiry['service'] }}
@endif
@foreach (($enquiry['answers'] ?? []) as $answer)
@if (trim((string) ($answer['a'] ?? '')) !== ''){{ $answer['q'] }}: {{ $answer['a'] }}
@endif
@endforeach
@if (! empty($enquiry['message']))

{{ $enquiry['message'] }}
@endif

They agreed to be contacted about this. Reply to them directly, and see everything under Website → Enquiries.
