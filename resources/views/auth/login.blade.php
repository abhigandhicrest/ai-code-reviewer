@extends('layouts.app')

@section('title', 'Login — AI Code Reviewer')

@section('content')
<div class="card auth-card">
    <h1>Login</h1>
    <p class="sub">Sign in to review code with local CodeGemma.</p>

    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

        <label for="password">Password</label>
        <input id="password" type="password" name="password" required>

        <label style="display:flex;align-items:center;gap:.45rem;margin-bottom:1rem;">
            <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
            <span class="muted">Remember me</span>
        </label>

        <button type="submit">Login</button>
    </form>
    <p class="muted" style="margin-top:1rem;">No account? <a href="{{ route('register') }}">Register</a></p>
</div>
@endsection
