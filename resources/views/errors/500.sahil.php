@extends('errors.layout')
@section('title')Server error@endsection
@section('content')
    <h1>{{ __('errors.server_error_title') }}</h1>
    <p>{{ __('errors.server_error_detail') }}</p>
@endsection
