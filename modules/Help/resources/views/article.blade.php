@extends('help::layout', ['title' => $article->title, 'description' => $article->summary])
@section('main')
<p>@if ($category)<a href="/c/{{ $category->slug }}">{{ $category->name }}</a>@else<a href="/">All help</a>@endif</p>
<article>
<h1>{{ $article->title }}</h1>
@if ($video)
<video controls preload="none" playsinline @if ($poster) poster="{{ $poster }}" @endif aria-label="Video: {{ $article->title }}"><source src="{{ $video }}"></video>
@endif
{!! $html !!}
</article>
<section id="feedback" aria-label="Was this helpful?">
@if ($thanked)
<p>Thanks for telling us.</p>
@else
<form method="post" action="/a/{{ $article->slug }}/feedback">
@csrf
<p>Was this helpful?
<button type="submit" name="helpful" value="1">Yes</button>
<button type="submit" name="helpful" value="0">No</button></p>
</form>
@endif
<p class="muted">Still stuck? Use <strong>Contact support</strong> in your WURK dashboard.</p>
</section>
@endsection
