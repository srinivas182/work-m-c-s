<x-mail::message>
# Your code

Use this code to {{ $reason }} to WURK:

<x-mail::panel>
<span style="font-size: 28px; font-weight: 700; letter-spacing: 6px;">{{ $code }}</span>
</x-mail::panel>

It expires in {{ $minutes }} minutes. If you didn’t ask for it, you can ignore this email — nobody can sign in without the code.

Never share this code with anyone, including WURK staff.
</x-mail::message>
