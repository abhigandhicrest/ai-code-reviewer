@extends('layouts.app')

@section('title', 'Prompt profiles — AI Code Reviewer')

@section('content')
    <h1>Prompt profiles</h1>
    <p class="sub">Admin-managed review focus profiles.</p>
    @if(session('status'))<div class="flash-ok">{{ session('status') }}</div>@endif

    <div class="card" style="margin-bottom:1rem;">
        <label>Create profile</label>
        <form method="POST" action="{{ route('profiles.store') }}">
            @csrf
            <label>Name</label>
            <input type="text" name="name" required>
            <label>Description</label>
            <input type="text" name="description">
            <label>Extra instructions</label>
            <textarea name="extra_instructions" style="min-height:100px;"></textarea>
            <label>Focus</label>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
                @foreach(['bugs','security','style','improve'] as $f)
                    <label style="display:flex;gap:.35rem;align-items:center;"><input type="checkbox" name="focus[]" value="{{ $f }}" @checked(in_array($f,['bugs','security','improve']))> {{ $f }}</label>
                @endforeach
            </div>
            <button type="submit">Create</button>
        </form>
    </div>

    <div class="card">
        <table>
            <thead><tr><th>Name</th><th>Slug</th><th>System</th><th></th></tr></thead>
            <tbody>
            @foreach($profiles as $profile)
                <tr>
                    <td>{{ $profile->name }}<div class="muted">{{ $profile->description }}</div></td>
                    <td><code>{{ $profile->slug }}</code></td>
                    <td>{{ $profile->is_system ? 'yes' : 'no' }}</td>
                    <td>
                        @unless($profile->is_system)
                            <form method="POST" action="{{ route('profiles.destroy', $profile) }}" onsubmit="return confirm('Delete profile?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn-secondary">Delete</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
