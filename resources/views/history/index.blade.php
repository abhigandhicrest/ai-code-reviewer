@extends('layouts.app')

@section('title', 'History — AI Code Reviewer')

@section('content')
    <h1>History</h1>
    <p class="sub">
        Reviews and generations.
        @if (!($quota['unlimited'] ?? false))
            Quota today: {{ $quota['used'] }}/{{ $quota['limit'] }}.
        @else
            Admin — unlimited quota.
        @endif
        Select two runs below to compare.
    </p>

    <form method="GET" action="{{ route('history.compare') }}" class="card" style="margin-bottom:1rem;">
        <div class="row">
            <div>
                <label for="a">Compare A</label>
                <input type="number" name="a" id="a" placeholder="Review ID" required>
            </div>
            <div>
                <label for="b">Compare B</label>
                <input type="number" name="b" id="b" placeholder="Review ID" required>
            </div>
            <button type="submit">Compare</button>
        </div>
    </form>

    <div class="card">
        <div style="overflow:auto;">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>When</th>
                    @if ($isAdmin)<th>User</th>@endif
                    <th>Title</th>
                    <th>Source</th>
                    <th>Files</th>
                    <th>Findings</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($runs as $run)
                    <tr>
                        <td>{{ $run->id }}</td>
                        <td>{{ $run->created_at?->format('Y-m-d H:i') }}</td>
                        @if ($isAdmin)<td>{{ $run->user?->email }}</td>@endif
                        <td>{{ $run->displayTitle() }}</td>
                        <td>
                            @if(in_array($run->source_type, ['generate', 'diff', 'path', 'directory'], true))
                                <span class="pill">{{ $run->source_type }}</span>
                            @else
                                {{ $run->source_type }}
                            @endif
                        </td>
                        <td>{{ $run->files_count }}</td>
                        <td>{{ $run->findings_count }}</td>
                        <td>@if($run->success)<span class="ok">ok</span>@else<span class="bad">failed</span>@endif</td>
                        <td><a href="{{ route('history.show', $run) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $isAdmin ? 9 : 8 }}" class="muted">No reviews yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:1rem;">{{ $runs->links() }}</div>
    </div>
@endsection
