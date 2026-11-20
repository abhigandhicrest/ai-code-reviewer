@extends('layouts.app')

@section('title', 'Usage — AI Code Reviewer')

@section('content')
    <h1>Usage monitoring</h1>
    <p class="sub">
        @if ($isAdmin)
            Admin view across all users.
        @else
            Your local review activity.
        @endif
    </p>

    <div class="stats">
        <div class="stat"><div class="n">{{ number_format($totalReviews) }}</div><div class="l">Total reviews</div></div>
        <div class="stat"><div class="n">{{ number_format($successReviews) }}</div><div class="l">Successful</div></div>
        <div class="stat"><div class="n">{{ number_format($failedReviews) }}</div><div class="l">Failed</div></div>
        <div class="stat"><div class="n">{{ number_format($avgLatency) }}</div><div class="l">Avg latency (ms)</div></div>
        <div class="stat"><div class="n">{{ number_format($totalTokens) }}</div><div class="l">Est. prompt tokens</div></div>
        @if ($isAdmin)
            <div class="stat"><div class="n">{{ number_format($usersCount) }}</div><div class="l">Users</div></div>
        @endif
    </div>

    <div class="grid" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));margin-bottom:1rem;">
        <div class="card">
            <label>By language</label>
            @forelse ($byLanguage as $row)
                <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border);">
                    <span>{{ $row->language }}</span>
                    <span class="pill">{{ $row->total }}</span>
                </div>
            @empty
                <p class="muted">No usage yet.</p>
            @endforelse
        </div>
        <div class="card">
            <label>Last 14 days</label>
            @forelse ($byDay as $row)
                <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--border);">
                    <span>{{ $row->day }}</span>
                    <span class="pill">{{ $row->total }}</span>
                </div>
            @empty
                <p class="muted">No usage yet.</p>
            @endforelse
        </div>
    </div>

    <div class="card">
        <label>Recent reviews</label>
        <div style="overflow:auto;">
            <table>
                <thead>
                <tr>
                    <th>When</th>
                    @if ($isAdmin)<th>User</th>@endif
                    <th>Lang</th>
                    <th>Tokens</th>
                    <th>Latency</th>
                    <th>Findings</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($recent as $log)
                    <tr>
                        <td>{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                        @if ($isAdmin)
                            <td>{{ $log->user?->email }}</td>
                        @endif
                        <td>{{ $log->language }}</td>
                        <td>{{ number_format($log->estimated_tokens) }}</td>
                        <td>{{ $log->latency_ms ? number_format($log->latency_ms).' ms' : '—' }}</td>
                        <td>{{ $log->findings_count }}</td>
                        <td>
                            @if ($log->success)
                                <span class="ok">ok</span>
                            @else
                                <span class="bad" title="{{ $log->error_message }}">failed</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $isAdmin ? 7 : 6 }}" class="muted">No reviews logged yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:1rem;">{{ $recent->links() }}</div>
    </div>
@endsection
