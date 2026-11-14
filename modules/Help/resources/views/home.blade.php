@extends('help::layout', ['title' => 'Help'])
@section('main')
<h1>How can we help?</h1>
@if (count($categories) === 0)
<p class="muted">Help articles are on their way.</p>
@else
<ul class="cards">
@foreach ($categories as $c)
<li><a href="/c/{{ $c['slug'] }}"><strong>{{ $c['name'] }}</strong></a>@if ($c['summary'])<br><span class="muted">{{ $c['summary'] }}</span>@endif<br><span class="muted">{{ $c['articles'] }} {{ $c['articles'] === 1 ? 'article' : 'articles' }}</span></li>
@endforeach
</ul>
@endif
@endsection
