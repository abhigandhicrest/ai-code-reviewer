@extends('layouts.app')

@section('title', 'Compare reviews — AI Code Reviewer')

@section('content')
    <h1>Compare reviews</h1>
    <p class="sub">#{{ $a->id }} vs #{{ $b->id }}</p>

    <div class="grid" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">
        <div class="card">
            <label>Review A #{{ $a->id }}</label>
            <p class="muted">{{ $a->created_at }} · {{ $a->language }} · {{ $a->findings_count }} findings</p>
            <p>{{ $a->summary }}</p>
            <a href="{{ route('history.show', $a) }}">Open A</a>
        </div>
        <div class="card">
            <label>Review B #{{ $b->id }}</label>
            <p class="muted">{{ $b->created_at }} · {{ $b->language }} · {{ $b->findings_count }} findings</p>
            <p>{{ $b->summary }}</p>
            <a href="{{ route('history.show', $b) }}">Open B</a>
        </div>
    </div>

    <div class="card" style="margin-top:1rem;">
        <label>Only in A ({{ count($onlyA) }})</label>
        @forelse($onlyA as $f)
            <p><span class="pill">{{ $f['severity'] ?? 'medium' }}</span> {{ $f['title'] ?? '' }} @if(!empty($f['file_path'])) <code>{{ $f['file_path'] }}</code> @endif</p>
        @empty
            <p class="muted">None</p>
        @endforelse
    </div>
    <div class="card" style="margin-top:1rem;">
        <label>Only in B ({{ count($onlyB) }})</label>
        @forelse($onlyB as $f)
            <p><span class="pill">{{ $f['severity'] ?? 'medium' }}</span> {{ $f['title'] ?? '' }} @if(!empty($f['file_path'])) <code>{{ $f['file_path'] }}</code> @endif</p>
        @empty
            <p class="muted">None</p>
        @endforelse
    </div>
    <div class="card" style="margin-top:1rem;">
        <label>Shared ({{ count($shared) }})</label>
        @forelse($shared as $f)
            <p><span class="pill">{{ $f['severity'] ?? 'medium' }}</span> {{ $f['title'] ?? '' }}</p>
        @empty
            <p class="muted">None</p>
        @endforelse
    </div>
@endsection
