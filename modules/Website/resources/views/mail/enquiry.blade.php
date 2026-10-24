New quote request from your website.

Name: {{ $enquiry['name'] }}
@if (! empty($enquiry['phone']))Phone: {{ $enquiry['phone'] }}
@endif
@if (! empty($enquiry['email']))Email: {{ $enquiry['email'] }}
@endif
@if (! empty($enquiry['service']))Service: {{ $enquiry['service'] }}
@endif

{{ $enquiry['message'] }}

They agreed to be contacted about this request. Reply to them directly, and see all requests under Website → Enquiries.
