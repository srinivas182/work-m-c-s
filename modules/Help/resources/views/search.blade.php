@extends('help::layout', ['title' => 'Search: '.$q, 'q' => $q])
@section('main')
<h1>Search</h1>
@if (count($results) > 0)
<p class="muted">{{ count($results) }} {{ count($results) === 1 ? 'article' : 'articles' }} for “{{ $q }}”.</p>
@include('help::_cards', ['articles' => $results])
@else
<p>We couldn’t find help for “{{ $q }}” yet. Try other words, or use <strong>Contact support</strong> in your WURK dashboard.</p>
@endif
@endsection
