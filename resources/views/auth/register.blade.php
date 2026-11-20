@extends('layouts.app')

@section('title', 'Register — AI Code Reviewer')

@section('content')
<div class="card auth-card">
    <h1>Register</h1>
    <p class="sub">Create an account to use the local code reviewer.</p>

    @if ($errors->any())
        <div class="error">
            <ul style="margin:0;padding-left:1.1rem;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}">
        @csrf
        <label for="name">Name</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus>

        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required>

        <label for="password">Password</label>
        <input id="password" type="password" name="password" required>

        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required>

        <button type="submit">Create account</button>
    </form>
    <p class="muted" style="margin-top:1rem;">Already registered? <a href="{{ route('login') }}">Login</a></p>
</div>
@endsection
