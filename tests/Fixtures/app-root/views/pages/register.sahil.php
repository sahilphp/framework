@extends('layouts.app')

@section('title')
    Register
@endsection

@section('content')
    <form method="POST" action="{{ link('register.submit') }}" class="register">
        @csrf
        <input name="name" value="{{ old.name }}">
        <input name="email" value="{{ old.email }}">
        <input type="hidden" name="rendered_token" value="{{ csrf_token() }}">
        @if(error('email'))
            <span class="default-error">{{ error('email') }}</span>
        @endif
        @if(error('email', 'register'))
            <span class="register-error">{{ error('email', 'register') }}</span>
        @endif
    </form>
    <a href="{{ link('home') }}">Home</a>
@endsection
