@extends('layouts.app')

@section('title', 'Coding standards — AI Code Reviewer')

@section('content')
    <h1>Coding standards</h1>
    <p class="sub">Per-language rules injected into review prompts, plus optional local linters.</p>
    @if(session('status'))<div class="flash-ok">{{ session('status') }}</div>@endif

    @foreach($standards as $standard)
        <div class="card" style="margin-bottom:1rem;">
            <form method="POST" action="{{ route('standards.update', $standard) }}">
                @csrf
                @method('PUT')
                <div class="row" style="margin-bottom:.5rem;">
                    <div>
                        <label>Language</label>
                        <input type="text" value="{{ $standard->language }}" disabled>
                    </div>
                    <div>
                        <label for="name_{{ $standard->id }}">Name</label>
                        <input type="text" name="name" id="name_{{ $standard->id }}" value="{{ old('name', $standard->name) }}" required>
                    </div>
                </div>
                <label for="rules_{{ $standard->id }}">Rules (sent to the model)</label>
                <textarea name="rules_text" id="rules_{{ $standard->id }}" style="min-height:140px;" required>{{ old('rules_text', $standard->rules_text) }}</textarea>
                <div class="row">
                    <div>
                        <label for="linter_{{ $standard->id }}">Linter</label>
                        <select name="linter" id="linter_{{ $standard->id }}">
                            @foreach($linters as $l)
                                <option value="{{ $l }}" @selected($standard->linter === $l)>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="args_{{ $standard->id }}">Linter args</label>
                        <input type="text" name="linter_args" id="args_{{ $standard->id }}" value="{{ old('linter_args', $standard->linter_args) }}" placeholder="e.g. --standard=PSR12">
                    </div>
                </div>
                <label style="display:flex;align-items:center;gap:.5rem;margin:.75rem 0;">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" value="1" @checked($standard->enabled)>
                    Enabled
                </label>
                <button type="submit">Save {{ $standard->language }}</button>
            </form>
        </div>
    @endforeach
@endsection
