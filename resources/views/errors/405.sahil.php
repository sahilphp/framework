@extends('errors.layout')
@section('title')Method not allowed@endsection
@section('content')
    <h1>{{ __('errors.method_title') }}</h1>
    <p>{{ __('errors.method_detail') }}</p>
@endsection
