<ul class="cards">
@foreach ($articles as $a)
<li><a href="/a/{{ $a['slug'] }}"><strong>{{ $a['title'] }}</strong></a>@if ($a['video']) <span class="muted">· video</span>@endif @if ($a['summary'])<br><span class="muted">{{ $a['summary'] }}</span>@endif</li>
@endforeach
</ul>
