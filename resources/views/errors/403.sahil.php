@extends('errors.layout')
@section('title')Access denied@endsection
@section('content')
    <h1>{{ __('errors.access_denied_title') }}</h1>
    <p>{{ __('errors.access_denied_detail') }}</p>
@endsection
