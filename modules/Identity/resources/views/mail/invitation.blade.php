<x-mail::message>
{{ $greeting }}

You’ve been invited to join **{{ $joining }}** on WURK as **{{ $role }}**.

<x-mail::button :url="$url">
Accept invitation
</x-mail::button>

This link works once and expires in 7 days. If you weren’t expecting it, you can ignore this email.
</x-mail::message>
