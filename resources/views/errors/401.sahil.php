@extends('errors.layout')

@section('title')Sign in required@endsection

@section('content')
    <h1>Please sign in to continue</h1>
    <p>This page is only available to signed-in users. Sign in and we will take you right back here.</p>
@endsection

@section('actions')
    <a class="error__link" href="/login">Sign in</a>
@endsection
