<!doctype html>
<html><body style="font-family:system-ui,sans-serif;font-size:15px;line-height:1.5;color:#111">
<div style="max-width:560px;margin:0 auto">{!! $htmlBody !!}
<p style="color:#666;font-size:12px;margin-top:32px">You’re getting this because you agreed to hear from {{ $business }}. <a href="{{ $stopUrl }}">Stop these messages</a>.</p>
</div>
<img src="{{ $openUrl }}" width="1" height="1" alt="">
</body></html>
