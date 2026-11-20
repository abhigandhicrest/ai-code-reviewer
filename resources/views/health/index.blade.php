@extends('layouts.app')

@section('title', 'Health — AI Code Reviewer')

@section('content')
    <h1>System health</h1>
    <p class="sub">Overall: <strong class="{{ $overall === 'ok' ? 'ok' : 'bad' }}">{{ $overall }}</strong></p>

    <div class="stats">
        @foreach($checks as $name => $check)
            <div class="stat">
                <div class="n {{ ($check['status'] ?? '') === 'ok' ? 'ok' : 'bad' }}">{{ strtoupper($check['status'] ?? 'down') }}</div>
                <div class="l">{{ $name }}</div>
                <div class="muted" style="margin-top:.35rem;font-size:.85rem;">
                    {{ $check['detail'] ?? '' }}
                    @if(!is_null($check['latency_ms'] ?? null)) · {{ $check['latency_ms'] }} ms @endif
                </div>
            </div>
        @endforeach
    </div>
@endsection
