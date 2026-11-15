@extends('errors.layout')
@section('title')Sign in required@endsection
@section('content')
    <h1>{{ __('errors.signin_required_title') }}</h1>
    <p>{{ __('errors.signin_required_detail') }}</p>
@endsection
@section('actions')
    <a class="error__link" href="/login">{{ __('errors.sign_in') }}</a>
@endsection
