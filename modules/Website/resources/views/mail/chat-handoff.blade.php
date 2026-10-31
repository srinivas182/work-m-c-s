Someone asked, in your website chat, for {{ $business }} to get back to them.

Name: {{ $chat['name'] }}
@if (! empty($chat['phone']))Phone: {{ $chat['phone'] }}
@endif
@if (! empty($chat['email']))Email: {{ $chat['email'] }}
@endif
@if (! empty($chat['message']))

{{ $chat['message'] }}
@endif

The conversation:
@foreach ($chat['transcript'] as $line)
{{ $line['from'] === 'visitor' ? 'Them' : 'Chat' }}: {{ $line['text'] }}
@endforeach

They agreed to be contacted about this. See it under Customers → Chats.
