@extends('errors.layout')
@section('title', 'Too many tries')
@section('code', 'Error 429')
@section('heading', 'That was a lot of tries in a short time')
@section('message')
    <p>Please wait a minute, then try again.</p>
@endsection
