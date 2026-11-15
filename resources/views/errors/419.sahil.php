@extends('errors.layout')
@section('title')Page expired@endsection
@section('content')
    <h1>{{ __('errors.expired_title') }}</h1>
    <p>{{ __('errors.expired_detail') }}</p>
@endsection
