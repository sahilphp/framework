@extends('errors.layout')
@section('title')Too many requests@endsection
@section('content')
    <h1>{{ __('errors.too_many_title') }}</h1>
    <p>{{ __('errors.too_many_detail') }}</p>
@endsection
