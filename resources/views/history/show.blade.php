@extends('layouts.app')

@section('title', 'Review #'.$run->id.' — AI Code Reviewer')

@section('styles')
        .code-block { max-height:420px; }
        .actions { margin:1rem 0; }
        .actions .btn { text-decoration: none; }
        @media print {
            .topnav, .actions, #severity-filter, label[for="severity-filter"], .theme-toggle { display:none !important; }
            body { background:#fff; color:#000; }
            .card, .finding { border-color:#ccc; background:#fff; box-shadow:none; }
        }
@endsection

@section('content')
    <h1>{{ $run->isGenerate() ? 'Generate' : 'Review' }} #{{ $run->id }}</h1>
    <p class="sub">
        {{ $run->created_at?->format('Y-m-d H:i') }}
        · {{ $run->language }}
        @unless($run->isGenerate())
            · {{ $run->files_count }} file(s)
            · {{ $run->chunks_count }} chunk(s)
        @endunless
        · <span class="pill">{{ $run->source_type }}</span>
        @if($run->promptProfile) · profile: {{ $run->promptProfile->name }} @endif
        · @if($run->success)<span class="ok">ok</span>@else<span class="bad">failed</span>@endif
    </p>

    <div class="actions">
        <a class="btn" href="{{ route('history.index') }}">Back</a>
        <a class="btn" href="{{ route('history.export', $run) }}">Export Markdown</a>
        <a class="btn" href="{{ route('history.show', $run) }}?print=1">Print / PDF</a>
        @if($run->isGenerate())
            <a class="btn" href="{{ route('generate.index') }}">New generation</a>
            <form method="POST" action="{{ route('history.rerun', $run) }}">@csrf<button type="submit">Re-run in Generate</button></form>
        @else
            <form method="POST" action="{{ route('history.rerun', $run) }}">@csrf<button type="submit">Re-run in editor</button></form>
        @endif
    </div>

    @if(session('status'))
        <div class="flash-ok">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif
    @if($run->error_message)<div class="error">{{ $run->error_message }}</div>@endif

    @if($canApply ?? false)
        <div class="card" style="margin-bottom:1rem;">
            <label>Apply improved code to disk</label>
            <p class="hint">Writes full-file improved code under the project root. Originals are backed up to <code>storage/app/acr-backups/{{ $run->id }}/</code>. Diff-mode hunks are never applied.</p>
            <form method="POST" action="{{ route('history.apply', $run) }}">
                @csrf
                <label for="project_root">Project root</label>
                <input type="text" name="project_root" id="project_root" value="{{ old('project_root', $run->project_root) }}" placeholder="D:\laragon\www\my-app" required>
                <label style="display:flex;align-items:center;gap:.5rem;margin:.75rem 0;">
                    <input type="checkbox" name="confirm" value="1" required>
                    I understand this overwrites files on disk
                </label>
                <button type="submit">Apply fixes</button>
            </form>
        </div>
    @endif

    <div class="card" style="margin-bottom:1rem;">
        <label>{{ $run->isGenerate() ? 'Notes' : 'Summary' }}</label>
        <p>{{ $run->summary ?: '—' }}</p>
    </div>

    @unless($run->isGenerate())
    <div class="card" style="margin-bottom:1rem;">
        <label for="severity-filter">Findings</label>
        <select id="severity-filter">
            <option value="all">All severities</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
        </select>
        <div id="findings-box" style="margin-top:.75rem;"
             data-findings='@json($run->findings ?? [])'></div>
    </div>

    @if($run->files && $run->files->count())
        <div class="card" style="margin-bottom:1rem;">
            <label>Files in this run</label>
            <ul>
                @foreach($run->files as $file)
                    <li><code>{{ $file->path }}</code> ({{ $file->language }})</li>
                @endforeach
            </ul>
        </div>
    @endif
    @endunless

    @php
        $generatedFiles = ($run->files ?? collect())->filter(fn ($f) => filled($f->improved_code));
    @endphp
    @if($generatedFiles->count())
        <div class="card" style="margin-bottom:1rem;">
            <label>Generated code</label>
            @foreach($generatedFiles as $file)
                @unless($run->isGenerate())
                    <p class="muted" style="margin:.75rem 0 .35rem;"><code>{{ $file->path }}</code></p>
                @endunless
                <pre class="code-block">{{ $file->improved_code }}</pre>
            @endforeach
        </div>
    @elseif($run->improved_code)
        <div class="card" style="margin-bottom:1rem;">
            <label>Generated code</label>
            <pre class="code-block">{{ $run->improved_code }}</pre>
        </div>
    @endif

    <div class="card">
        <label>{{ $run->isGenerate() ? 'Instruction (redacted)' : 'Original code (redacted)' }}</label>
        <pre class="code-block">{{ $run->code }}</pre>
    </div>
@endsection

@section('scripts')
<script>
    @unless($run->isGenerate())
    const box = document.getElementById('findings-box');
    const filter = document.getElementById('severity-filter');
    const findings = JSON.parse(box.dataset.findings || '[]');
    function esc(v){ return String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'); }
    function render() {
        const sev = filter.value;
        const rows = findings.filter(f => sev === 'all' || (f.severity || 'medium') === sev);
        box.innerHTML = rows.map(f => {
            const s = esc(f.severity || 'medium');
            return `<div class="finding"><div class="finding-head"><span class="sev ${s}">${s}</span><h3>${esc(f.title||'Issue')}</h3></div>
                <p>${esc(f.explanation||'')}</p>
                ${f.file_path ? `<p class="muted">File: ${esc(f.file_path)}</p>` : ''}
                ${f.line_hint ? `<p class="muted">${esc(f.line_hint)}</p>` : ''}</div>`;
        }).join('') || '<p class="muted">No findings for this filter.</p>';
    }
    filter.addEventListener('change', render);
    render();
    @endunless
    @if($print) window.print(); @endif
</script>
@endsection
