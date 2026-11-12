@extends('help::layout', ['title' => $category->name, 'description' => $category->summary])
@section('main')
<p><a href="/">All help</a></p>
<h1>{{ $category->name }}</h1>
@include('help::_cards', ['articles' => $articles])
@endsection
