@extends('errors.layout')
@section('title', 'You can’t open this')
@section('code', 'Error 403')
@section('heading', 'You can’t open this page')
@section('message')
    <p>Your account doesn’t have access to it. If you think it should, ask the person who manages your team to change your role.</p>
@endsection
