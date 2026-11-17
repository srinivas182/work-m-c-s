@extends('errors.layout')
@section('title', 'Back soon')
@section('code', 'Error 503')
@section('heading', 'We’ll be back in a few minutes')
@section('message')
    <p>{{ $exception?->getMessage() ?: 'We’re making WURK better. Nothing you’ve saved is affected.' }}</p>
@endsection
@section('actions')
    <a class="button primary" href="">Try again</a>
@endsection
