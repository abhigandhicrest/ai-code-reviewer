@extends('layouts.app')

@section('title', 'Audit log — AI Code Reviewer')

@section('content')
    <h1>Audit log</h1>
    <p class="sub">Metadata only — no full source code.</p>
    <div class="card">
        <table>
            <thead>
            <tr><th>When</th><th>User</th><th>Action</th><th>Subject</th><th>Meta</th><th>IP</th></tr>
            </thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td>{{ $log->created_at }}</td>
                    <td>{{ $log->user?->email ?? '—' }}</td>
                    <td>{{ $log->action }}</td>
                    <td>{{ $log->subject_type }} #{{ $log->subject_id }}</td>
                    <td><code style="font-size:.75rem;">{{ \Illuminate\Support\Str::limit(json_encode($log->meta_json), 80) }}</code></td>
                    <td>{{ $log->ip_address }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No audit events yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div style="margin-top:1rem;">{{ $logs->links() }}</div>
    </div>
@endsection
