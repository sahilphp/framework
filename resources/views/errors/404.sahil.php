@extends('errors.layout')
@section('title')Page not found@endsection
@section('content')
    <h1>{{ __('errors.page_missing') }}</h1>
    <p>{{ __('errors.page_missing_detail') }}</p>
@endsection
