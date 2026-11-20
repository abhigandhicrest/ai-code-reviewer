@extends('layouts.app')

@section('title', 'AI Code Reviewer')

@section('styles')
        #code { min-height:220px; }
        #ignore_patterns, #diff_text { min-height:90px; }
@endsection

@section('content')
    <h1>AI Code Reviewer</h1>
    <p class="sub">Path scan + file pick · git diff · multi-file / zip · local CodeGemma</p>

    @php
        $llmOk = ($health['llm'] ?? 'down') === 'ok';
        $apiOk = ($health['status'] ?? 'down') === 'ok';
        $quota = $quota ?? ['unlimited'=>true,'used'=>0,'limit'=>0,'remaining'=>null];
    @endphp
    <div class="badge">
        <span class="dot {{ $apiOk ? 'ok' : 'bad' }}"></span> API: {{ $apiOk ? 'up' : 'down' }}
        &nbsp;·&nbsp;
        <span class="dot {{ $llmOk ? 'ok' : 'bad' }}"></span> LLM: {{ $llmOk ? 'ok' : 'down' }}
        &nbsp;·&nbsp;
        <span id="quota-text">
            @if($quota['unlimited'] ?? false) Quota: unlimited @else Quota: {{ $quota['used'] }}/{{ $quota['limit'] }} today @endif
        </span>
        @if(!empty($etaMs))
            &nbsp;·&nbsp; ETA ~ {{ number_format($etaMs/1000, 1) }}s
        @endif
    </div>

    @if(session('status'))
        <div class="flash-ok">{{ session('status') }}</div>
    @endif
    <div id="error-box" class="error" style="display:none"></div>

    <div class="grid two">
        <div class="card">
            <form method="POST" action="{{ route('review.stream') }}" id="review-form" enctype="multipart/form-data">
                @csrf
                <div class="row">
                    <div>
                        <label for="language">Default language</label>
                        <select name="language" id="language">
                            @foreach($languages as $lang)
                                <option value="{{ $lang }}" @selected($language === $lang)>{{ $lang }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="prompt_profile_id">Prompt profile</label>
                        <select name="prompt_profile_id" id="prompt_profile_id">
                            <option value="">General (default)</option>
                            @foreach($profiles as $profile)
                                <option value="{{ $profile->id }}" @selected((string)$selectedProfile === (string)$profile->id)>
                                    {{ $profile->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <p class="hint">Coding standards: PSR-12 / PEP 8 / ESLint (editable under Standards).</p>

                <label for="project_path">Local project path</label>
                <div class="path-row" style="margin-bottom:.85rem;">
                    <input type="text" name="project_path" id="project_path" value="{{ $projectPath ?? '' }}" placeholder="D:\laragon\www\my-app" autocomplete="off">
                    <button type="button" class="btn-secondary" id="scan-btn">Scan files</button>
                </div>
                <p class="hint">Allowed roots: {{ implode(', ', $allowedRoots ?? []) }}. Reads <code>.reviewignore</code> from the project root when present.</p>

                <label class="hint" style="display:flex;align-items:center;gap:.5rem;margin:.35rem 0 .5rem;">
                    <input type="hidden" name="use_git_diff" value="0">
                    <input type="checkbox" name="use_git_diff" id="use_git_diff" value="1">
                    Review git diff (base…head) instead of full tree
                </label>
                <div class="row" id="git-ref-row">
                    <div>
                        <label for="git_base">Git base</label>
                        <input type="text" name="git_base" id="git_base" value="{{ old('git_base', 'main') }}" placeholder="main">
                    </div>
                    <div>
                        <label for="git_head">Git head</label>
                        <input type="text" name="git_head" id="git_head" value="{{ old('git_head', 'HEAD') }}" placeholder="HEAD">
                    </div>
                    <div style="flex:0 0 auto;">
                        <label>&nbsp;</label>
                        <button type="button" class="btn-secondary" id="git-diff-btn">Load git diff</button>
                    </div>
                </div>
                <p class="hint">Uses local <code>git diff base...head</code> under the project path. Load preview fills the diff box below.</p>

                <label for="ignore_patterns">Extra ignore patterns</label>
                <textarea name="ignore_patterns" id="ignore_patterns" placeholder="*.md&#10;tests/&#10;docs/**">{{ old('ignore_patterns', '') }}</textarea>
                <p class="hint">One pattern per line (gitignore-style). Merged with <code>.reviewignore</code>.</p>

                <div id="file-picker" class="file-picker" aria-live="polite">
                    <div class="picker-toolbar">
                        <span id="picker-count">0 selected</span>
                        <button type="button" class="btn-secondary" id="select-all-btn" style="padding:.35rem .7rem;font-size:.8rem;">Select all</button>
                        <button type="button" class="btn-secondary" id="select-none-btn" style="padding:.35rem .7rem;font-size:.8rem;">Select none</button>
                    </div>
                    <div id="file-picker-list"></div>
                </div>

                <label for="code_dir">Or choose project folder</label>
                <input type="file" name="code_dir[]" id="code_dir" webkitdirectory directory multiple>
                <p class="hint" id="dir-count-hint">Browser folder picker — pick files in the checklist before review.</p>

                <label for="diff_text">Or paste git unified diff</label>
                <textarea name="diff_text" id="diff_text" placeholder="git diff&#10;git diff main...HEAD">{{ old('diff_text', '') }}</textarea>
                <label for="diff_file">Or upload .diff / .patch</label>
                <input type="file" name="diff_file" id="diff_file" accept=".diff,.patch,text/plain,text/x-diff">

                <label for="code_files">Or upload files (multi-select)</label>
                <input type="file" name="code_files[]" id="code_files" multiple>

                <label for="code_zip">Or upload zip</label>
                <input type="file" name="code_zip" id="code_zip" accept=".zip,application/zip">
                <p class="hint">
                    @if(($maxFiles ?? 0) > 0)
                        Max {{ $maxFiles }} files,
                    @else
                        No file-count cap;
                    @endif
                    1 MB each, depth 8. Path / selected files win over zip; diff wins when path is empty. Secrets are redacted before the model sees them.
                </p>

                <label for="code">Or paste code</label>
                <textarea name="code" id="code" placeholder="Paste code…">{{ $code }}</textarea>

                <label class="hint" style="display:flex;align-items:center;gap:.5rem;margin:.75rem 0;">
                    <input type="hidden" name="generate_code" value="0">
                    <input type="checkbox" name="generate_code" id="generate_code" value="1" checked>
                    Generate improved / fixed code for each file
                </label>

                <div class="actions">
                    <button type="submit" id="submit-btn">Review code</button>
                    <button type="button" class="btn-secondary" id="cancel-btn" disabled>Cancel</button>
                </div>
                <p class="loading" id="loading">Reviewing…</p>
                <p class="progress-line" id="progress-line">Progress: 0%</p>
                <pre class="stream-box" id="stream-box"></pre>
            </form>
        </div>

        <div class="card">
            <label>Review result</label>
            <div class="meta" id="meta-box" style="display:none">
                <span id="latency-text"></span>
                <span id="model-text"></span>
            </div>
            <div style="margin-bottom:.75rem;">
                <label for="severity-filter">Filter severity</label>
                <select id="severity-filter">
                    <option value="all">All</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
            </div>
            <div id="result-box"><p class="empty">Submit code to see findings.</p></div>
        </div>
    </div>
@endsection

@section('scripts')
<script>
    const form = document.getElementById('review-form');
    const btn = document.getElementById('submit-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    const loading = document.getElementById('loading');
    const progressLine = document.getElementById('progress-line');
    const streamBox = document.getElementById('stream-box');
    const resultBox = document.getElementById('result-box');
    const errorBox = document.getElementById('error-box');
    const metaBox = document.getElementById('meta-box');
    const latencyText = document.getElementById('latency-text');
    const modelText = document.getElementById('model-text');
    const severityFilter = document.getElementById('severity-filter');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const streamUrl = @json(route('review.stream'));
    let abortController = null;
    let lastPayload = null;

    function showError(message) {
        errorBox.style.display = 'block';
        errorBox.textContent = message;
    }
    function clearError() {
        errorBox.style.display = 'none';
        errorBox.textContent = '';
    }
    function escapeHtml(value) {
        return String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');
    }
    function updateQuota(quota) {
        const el = document.getElementById('quota-text');
        if (!el || !quota) return;
        el.textContent = quota.unlimited ? 'Quota: unlimited' : `Quota: ${quota.used}/${quota.limit} today`;
    }
    function renderResult(payload) {
        lastPayload = payload;
        const severity = severityFilter.value;
        const findings = (payload.findings || []).filter(f => severity === 'all' || (f.severity || 'medium') === severity);
        const summary = payload.summary || '';
        const improved = payload.improved_code || '';
        let html = '';
        if (summary) html += `<p class="summary">${escapeHtml(summary)}</p>`;
        for (const finding of findings) {
            const sev = escapeHtml(finding.severity || 'medium');
            html += `<div class="finding"><div class="finding-head"><span class="sev ${sev}">${sev}</span><h3>${escapeHtml(finding.title || 'Issue')}</h3></div>
                <p>${escapeHtml(finding.explanation || '')}</p>
                ${finding.file_path ? `<p class="hint-line">File: ${escapeHtml(finding.file_path)}</p>` : ''}
                ${finding.line_hint ? `<p class="hint-line">${escapeHtml(finding.line_hint)}</p>` : ''}
            </div>`;
        }
        if (!findings.length && !summary && !improved) html = `<p class="empty">No findings for this filter.</p>`;
        const fileResults = payload.file_results || [];
        const generated = fileResults.filter(f => f.improved_code);
        if (generated.length) {
            html += `<label>Generated code</label>`;
            for (const file of generated) {
                html += `<p class="hint-line" style="margin:.5rem 0 .25rem;"><code>${escapeHtml(file.path || 'file')}</code></p>`;
                html += `<pre class="code-block">${escapeHtml(file.improved_code)}</pre>`;
            }
        } else if (improved) {
            html += `<label>Generated code</label><pre class="code-block">${escapeHtml(improved)}</pre>`;
        }
        resultBox.innerHTML = html;
        metaBox.style.display = 'block';
        latencyText.textContent = `Latency: ${Number(payload.latency_ms||0).toLocaleString()} ms · Files: ${payload.files_count||1} · Chunks: ${payload.chunks_count||1}`;
        modelText.innerHTML = payload.model ? `<br>Model: ${escapeHtml(payload.model)}` : '';
        if (payload.review_run_id) {
            modelText.innerHTML += `<br><a href="/history/${payload.review_run_id}">Open in history</a> · <a href="/history/${payload.review_run_id}/export.md">Export Markdown</a>`;
        }
    }
    severityFilter.addEventListener('change', () => { if (lastPayload) renderResult(lastPayload); });

    async function parseSseStream(response, onEvent) {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });
            let splitAt;
            while ((splitAt = buffer.indexOf('\n\n')) !== -1) {
                const rawEvent = buffer.slice(0, splitAt);
                buffer = buffer.slice(splitAt + 2);
                let eventName = 'message';
                const dataLines = [];
                for (const line of rawEvent.split('\n')) {
                    if (line.startsWith('event:')) eventName = line.slice(6).trim();
                    else if (line.startsWith('data:')) dataLines.push(line.slice(5).trim());
                }
                if (!dataLines.length) continue;
                try { onEvent(eventName, JSON.parse(dataLines.join('\n'))); } catch {}
            }
        }
    }

    cancelBtn.addEventListener('click', () => {
        if (abortController) abortController.abort();
    });

    const scanBtn = document.getElementById('scan-btn');
    const scanUrl = @json(route('review.scan'));
    const gitDiffUrl = @json(route('review.git-diff'));
    const historyBase = @json(url('/history'));
    const projectPathInput = document.getElementById('project_path');
    const ignorePatterns = document.getElementById('ignore_patterns');
    const filePicker = document.getElementById('file-picker');
    const filePickerList = document.getElementById('file-picker-list');
    const pickerCount = document.getElementById('picker-count');
    const gitDiffBtn = document.getElementById('git-diff-btn');
    const useGitDiff = document.getElementById('use_git_diff');
    const maxFiles = {{ (int) ($maxFiles ?? 0) }};
    let pickerMode = null; // 'path' | 'dir'

    function selectedPathInputs() {
        return Array.from(filePickerList.querySelectorAll('input[name="selected_paths[]"]:checked'));
    }
    function updatePickerCount() {
        const n = selectedPathInputs().length;
        const total = filePickerList.querySelectorAll('input[name="selected_paths[]"]').length;
        const capped = maxFiles > 0 && n > maxFiles;
        pickerCount.textContent = `${n} selected / ${total} listed` + (capped ? ` (will cap at ${maxFiles})` : '');
    }
    function renderFilePicker(files, mode) {
        pickerMode = mode;
        filePickerList.innerHTML = '';
        for (const file of files) {
            const path = file.path || file;
            const lang = file.language ? ` · ${file.language}` : '';
            const bytes = file.bytes != null ? ` · ${Number(file.bytes).toLocaleString()} B` : '';
            const label = document.createElement('label');
            label.className = 'file-item';
            label.innerHTML = `<input type="checkbox" name="selected_paths[]" value="${escapeHtml(path)}" checked>
                <span><code>${escapeHtml(path)}</code>${escapeHtml(lang + bytes)}</span>`;
            filePickerList.appendChild(label);
        }
        filePicker.classList.add('show');
        updatePickerCount();
        filePickerList.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', updatePickerCount);
        });
    }
    document.getElementById('select-all-btn').addEventListener('click', () => {
        filePickerList.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = true; });
        updatePickerCount();
    });
    document.getElementById('select-none-btn').addEventListener('click', () => {
        filePickerList.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; });
        updatePickerCount();
    });

    if (gitDiffBtn) {
        gitDiffBtn.addEventListener('click', async () => {
            clearError();
            const path = (projectPathInput.value || '').trim();
            if (!path) { showError('Enter a local project path for git diff.'); return; }
            gitDiffBtn.disabled = true;
            gitDiffBtn.textContent = 'Loading…';
            try {
                const body = new FormData();
                body.append('_token', csrf);
                body.append('project_path', path);
                body.append('git_base', document.getElementById('git_base').value || 'main');
                body.append('git_head', document.getElementById('git_head').value || 'HEAD');
                const response = await fetch(gitDiffUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body,
                });
                const text = await response.text();
                let data = {};
                try { data = JSON.parse(text); } catch {}
                if (!response.ok) {
                    throw new Error(data.detail || data.message || text.slice(0, 300) || 'Git diff failed');
                }
                document.getElementById('diff_text').value = data.diff || '';
                if (useGitDiff) useGitDiff.checked = true;
                streamBox.classList.add('show');
                streamBox.textContent = `[note] Loaded git diff ${data.base}...${data.head} (${Number(data.bytes||0).toLocaleString()} bytes) from ${data.project_label}.\n`;
            } catch (err) {
                showError(err.message || 'Git diff failed.');
            } finally {
                gitDiffBtn.disabled = false;
                gitDiffBtn.textContent = 'Load git diff';
            }
        });
    }

    scanBtn.addEventListener('click', async () => {
        clearError();
        const path = (projectPathInput.value || '').trim();
        if (!path) { showError('Enter a local project path to scan.'); return; }
        scanBtn.disabled = true;
        scanBtn.textContent = 'Scanning…';
        try {
            const body = new FormData();
            body.append('_token', csrf);
            body.append('project_path', path);
            body.append('ignore_patterns', ignorePatterns.value || '');
            const response = await fetch(scanUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            const text = await response.text();
            let data = {};
            try { data = JSON.parse(text); } catch {}
            if (!response.ok) {
                throw new Error(data.detail || data.message || text.slice(0, 300) || 'Scan failed');
            }
            renderFilePicker(data.files || [], 'path');
            if (data.truncated) {
                streamBox.classList.add('show');
                streamBox.textContent = `[note] Scan list truncated for UI (${(data.files||[]).length} shown). Use ignore patterns to narrow.\n`;
            }
            if (data.ignored_count) {
                streamBox.classList.add('show');
                streamBox.textContent += `[note] Ignored ${data.ignored_count} path(s) via skip/.reviewignore patterns.\n`;
            }
        } catch (err) {
            showError(err.message || 'Scan failed.');
        } finally {
            scanBtn.disabled = false;
            scanBtn.textContent = 'Scan files';
        }
    });

    const codeDir = document.getElementById('code_dir');
    const dirCountHint = document.getElementById('dir-count-hint');
    function clientIgnoreMatch(path, patternsText) {
        const patterns = (patternsText || '').split(/\r?\n/).map(s => s.trim()).filter(s => s && !s.startsWith('#'));
        const base = path.split('/').pop();
        for (const p of patterns) {
            if (p.endsWith('/')) {
                const dir = p.replace(/\/$/, '');
                if (path === dir || path.startsWith(dir + '/')) return true;
            } else if (p.startsWith('*.')) {
                if (base.toLowerCase().endsWith(p.slice(1).toLowerCase())) return true;
            } else if (p.includes('*')) {
                const re = new RegExp('^' + p.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*\*/g, '.*').replace(/\*/g, '[^/]*') + '$', 'i');
                if (re.test(path) || re.test(base)) return true;
            } else if (path === p || path.endsWith('/' + p) || base === p) {
                return true;
            }
        }
        return false;
    }
    if (codeDir && dirCountHint) {
        codeDir.addEventListener('change', () => {
            const files = codeDir.files ? Array.from(codeDir.files) : [];
            const ignoreText = ignorePatterns.value || '';
            const listed = [];
            for (const f of files) {
                const rel = (f.webkitRelativePath || f.name || '').replace(/\\/g, '/');
                if (!rel) continue;
                if (/\/(vendor|node_modules|\.git|storage)\//i.test('/' + rel + '/')) continue;
                if (clientIgnoreMatch(rel, ignoreText)) continue;
                listed.push({ path: rel, language: '', bytes: f.size });
            }
            listed.sort((a, b) => a.path.localeCompare(b.path));
            dirCountHint.textContent = listed.length
                ? `${listed.length} reviewable file(s) from folder — confirm selection below.`
                : 'No reviewable files in folder (or all ignored).';
            if (listed.length) renderFilePicker(listed, 'dir');
            else filePicker.classList.remove('show');
        });
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();
        const pathFilled = (projectPathInput.value || '').trim() !== '';
        const diffFilled = (document.getElementById('diff_text').value || '').trim() !== '' || (document.getElementById('diff_file').files||[]).length > 0;
        if (filePicker.classList.contains('show') && (pathFilled || pickerMode === 'dir') && !diffFilled) {
            if (selectedPathInputs().length === 0) {
                showError('Select at least one file from the scan/folder list (or clear the path/folder).');
                return;
            }
        }
        abortController = new AbortController();
        btn.disabled = true;
        cancelBtn.disabled = false;
        loading.classList.add('show');
        progressLine.classList.add('show');
        progressLine.textContent = 'Progress: 0%';
        streamBox.classList.add('show');
        streamBox.textContent = '';
        resultBox.innerHTML = `<p class="empty">Waiting for model output…</p>`;

        try {
            const response = await fetch(streamUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'text/event-stream', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
                signal: abortController.signal,
            });
            if (!response.ok) {
                if (response.status === 401 || response.status === 419) { window.location.href = @json(route('login')); return; }
                const text = await response.text();
                let detail = text || `Stream failed (${response.status})`;
                try {
                    const j = JSON.parse(text);
                    if (j.detail) detail = j.detail;
                    else if (j.message) detail = j.message;
                    else if (j.errors) detail = Object.values(j.errors).flat().join(' ');
                } catch {}
                throw new Error(detail);
            }
            let finished = false;
            await parseSseStream(response, (eventName, data) => {
                if (eventName === 'token') {
                    streamBox.textContent += data.content || '';
                    streamBox.scrollTop = streamBox.scrollHeight;
                } else if (eventName === 'progress') {
                    progressLine.textContent = `Progress: ${data.percent ?? 0}%` + (data.path ? ` · ${data.path}` : '');
                } else if (eventName === 'error') {
                    finished = true;
                    showError(data.detail || 'Review failed.');
                } else if (eventName === 'done') {
                    finished = true;
                    window.__lastReviewPayload = data;
                    renderResult(data);
                } else if (eventName === 'meta') {
                    updateQuota(data.quota);
                    if (window.__lastReviewPayload && data.review_run_id) {
                        window.__lastReviewPayload.review_run_id = data.review_run_id;
                        renderResult(window.__lastReviewPayload);
                        streamBox.textContent += `\n[note] Saved as review #${data.review_run_id}. Open history to apply fixes: ${historyBase}/${data.review_run_id}\n`;
                    }
                    if (data.secrets_redacted) {
                        streamBox.textContent += `\n[note] Redacted ${data.secrets_redacted} secret-like value(s).\n`;
                    }
                    if (!finished && data.error) {
                        finished = true;
                        showError(data.error);
                    }
                }
            });
            if (!finished) showError('Stream ended before a complete review was returned. For large project paths, wait for Progress to finish or review fewer files.');
        } catch (err) {
            if (err.name === 'AbortError') showError('Review cancelled.');
            else showError(err.message || 'Review request failed.');
        } finally {
            btn.disabled = false;
            cancelBtn.disabled = true;
            loading.classList.remove('show');
            abortController = null;
        }
    });
</script>
@endsection
