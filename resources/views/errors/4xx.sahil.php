@extends('errors.layout')

@section('title')Request error@endsection

@section('content')
    <h1>This request could not be completed</h1>
    <p>The server rejected the request ({{ reason }}). Check what you sent, then try again.</p>
@endsection
