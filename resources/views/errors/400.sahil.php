@extends('errors.layout')
@section('title')Bad Request@endsection
@section('content')
    <h1>{{ __('errors.bad_request_title') }}</h1>
    <p>{{ __('errors.bad_request_detail') }}</p>
@endsection
