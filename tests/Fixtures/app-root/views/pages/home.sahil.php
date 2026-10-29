@extends('layouts.app')

@section('title')
    {{ title ?? 'SahilPHP' }}
@endsection

@section('content')
    <h1>{{ heading }}</h1>
    <p>SahilPHP templates</p>
    <p>{{ attribution }}</p>
    <p>Rendered {{ rendered_at }}.</p>
@endsection
