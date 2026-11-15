@extends('errors.layout')
@section('title')Request error@endsection
@section('content')
    <h1>{{ __('errors.request_error_title') }}</h1>
    <p>{{ __('errors.request_error_detail', ['reason' => reason]) }}</p>
@endsection
