@extends('errors.layout')
@section('title', 'Something went wrong')
@section('code', 'Error 500')
@section('heading', 'Something went wrong on our side')
@section('message')
    <p>It isn’t anything you did. We’ve been told about it automatically and we’re looking at it.</p>
    <p>Please try again in a few minutes.</p>
@endsection
