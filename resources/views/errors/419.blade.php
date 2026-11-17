@extends('errors.layout')
@section('title', 'Page timed out')
@section('code', 'Error 419')
@section('heading', 'This page timed out')
@section('message')
    <p>For your safety, forms stop working after a while. Go back, refresh the page, and try again — nothing has been saved.</p>
@endsection
@section('actions')
    <a class="button primary" href="{{ url()->previous() }}">Go back</a>
    <a class="button secondary" href="/">Go to the home page</a>
@endsection
