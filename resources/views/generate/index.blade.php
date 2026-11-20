@extends('layouts.app')

@section('title', 'Generate code — AI Code Reviewer')

@section('styles')
        textarea { min-height:160px; }
        #instruction { min-height:140px; font-family: inherit; }
        #source-code { min-height:220px; font-family: ui-monospace, Consolas, monospace; font-size:.85rem; }
        .mode-toggle { display:inline-flex; border:1px solid var(--border,#d0d7de); border-radius:8px; overflow:hidden; margin-bottom:1rem; }
        .mode-toggle button { border:0; background:transparent; padding:.45rem 1rem; cursor:pointer; font:inherit; color:var(--muted,#57606a); }
        .mode-toggle button.active { background:var(--accent,#0969da); color:#fff; }
        .mode-toggle button + button { border-left:1px solid var(--border,#d0d7de); }
        details.raw-stream { margin-top:.75rem; }
        details.raw-stream summary { cursor:pointer; color:var(--muted,#57606a); font-size:.85rem; }
        .result-actions { display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.75rem; }
@endsection

@section('content')
    <h1>Generate code</h1>
    <p class="sub">Describe what you need — or paste code and ask for a change · same local CodeGemma model · streaming</p>

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
            <div class="mode-toggle" role="tablist">
                <button type="button" id="mode-generate" class="active" role="tab">Generate</button>
                <button type="button" id="mode-modify" role="tab">Modify code</button>
            </div>

            <form method="POST" action="{{ route('generate.stream') }}" id="generate-form">
                @csrf
                <input type="hidden" name="mode" id="mode-input" value="generate">

                <div class="row">
                    <div>
                        <label for="language">Language</label>
                        <select name="language" id="language">
                            @foreach($languages as $lang)
                                <option value="{{ $lang }}" @selected($language === $lang)>{{ $lang }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="prompt_profile_id">Prompt profile (optional)</label>
                        <select name="prompt_profile_id" id="prompt_profile_id">
                            <option value="">None</option>
                            @foreach($profiles as $profile)
                                <option value="{{ $profile->id }}" @selected((string)$selectedProfile === (string)$profile->id)>
                                    {{ $profile->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div id="source-block" style="display:none">
                    <label for="source_code">Code to modify</label>
                    <textarea name="source_code" id="source_code" placeholder="Paste the existing code you want changed…"></textarea>
                    <p class="hint">The model returns the complete modified file, not a diff.</p>
                </div>

                <label for="instruction" id="instruction-label">What to build</label>
                <textarea name="instruction" id="instruction" placeholder="e.g. Create a Laravel controller that lists users with pagination…" required>{{ $instruction }}</textarea>
                <p class="hint" id="instruction-hint">One file / short module works best with the local 7B model.</p>

                <label for="context">Optional context</label>
                <textarea name="context" id="context" placeholder="Paste related code, schemas, or constraints…">{{ $context }}</textarea>

                <div class="actions">
                    <button type="submit" id="submit-btn">Generate</button>
                    <button type="button" class="btn-secondary" id="cancel-btn" disabled>Cancel</button>
                    <button type="button" class="btn-secondary" id="copy-btn" disabled>Copy code</button>
                </div>
                <p class="loading" id="loading">Generating…</p>
                <p class="progress-line" id="progress-line">Progress: 0%</p>
                <details class="raw-stream" id="raw-stream-wrap" style="display:none">
                    <summary>Raw model output</summary>
                    <pre class="stream-box show" id="stream-box"></pre>
                </details>
            </form>
        </div>

        <div class="card">
            <div class="result-head">
                <label>Generated code</label>
                <button type="button" class="btn-copy" id="copy-result-btn" disabled>Copy code</button>
            </div>
            <div class="meta" id="meta-box" style="display:none">
                <span id="latency-text"></span>
                <span id="model-text"></span>
            </div>
            <div id="result-box"><p class="empty">Submit an instruction to generate code.</p></div>
            <div class="result-actions">
                <button type="button" class="btn-secondary" id="iterate-btn" disabled>Modify this code →</button>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
<script>
    const form = document.getElementById('generate-form');
    const btn = document.getElementById('submit-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    const copyBtn = document.getElementById('copy-btn');
    const copyResultBtn = document.getElementById('copy-result-btn');
    const iterateBtn = document.getElementById('iterate-btn');
    const loading = document.getElementById('loading');
    const progressLine = document.getElementById('progress-line');
    const streamBox = document.getElementById('stream-box');
    const rawStreamWrap = document.getElementById('raw-stream-wrap');
    const resultBox = document.getElementById('result-box');
    const errorBox = document.getElementById('error-box');
    const metaBox = document.getElementById('meta-box');
    const latencyText = document.getElementById('latency-text');
    const modelText = document.getElementById('model-text');
    const sourceBlock = document.getElementById('source-block');
    const sourceInput = document.getElementById('source_code');
    const instructionLabel = document.getElementById('instruction-label');
    const instructionHint = document.getElementById('instruction-hint');
    const instructionInput = document.getElementById('instruction');
    const modeInput = document.getElementById('mode-input');
    const modeGenerateBtn = document.getElementById('mode-generate');
    const modeModifyBtn = document.getElementById('mode-modify');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const streamUrl = @json(route('generate.stream'));
    let abortController = null;
    let lastCode = '';
    let currentMode = 'generate';

    function setMode(mode) {
        currentMode = mode;
        modeInput.value = mode;
        const isModify = mode === 'modify';
        modeGenerateBtn.classList.toggle('active', !isModify);
        modeModifyBtn.classList.toggle('active', isModify);
        sourceBlock.style.display = isModify ? 'block' : 'none';
        sourceInput.required = isModify;
        instructionLabel.textContent = isModify ? 'What to change' : 'What to build';
        instructionInput.placeholder = isModify
            ? 'e.g. Add input validation and return 422 on invalid data…'
            : 'e.g. Create a Laravel controller that lists users with pagination…';
        instructionHint.textContent = isModify
            ? 'Describe the change precisely — the full modified file is returned.'
            : 'One file / short module works best with the local 7B model.';
        btn.textContent = isModify ? 'Modify code' : 'Generate';
        loading.textContent = isModify ? 'Modifying…' : 'Generating…';
    }
    modeGenerateBtn.addEventListener('click', () => setMode('generate'));
    modeModifyBtn.addEventListener('click', () => setMode('modify'));

    function showError(message) {
        errorBox.style.display = 'block';
        errorBox.textContent = message;
    }
    function clearError() {
        errorBox.style.display = 'none';
        errorBox.textContent = '';
    }
    function escapeHtml(value) {
        return String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;');
    }
    function setCopyEnabled(enabled) {
        copyBtn.disabled = !enabled;
        copyResultBtn.disabled = !enabled;
        iterateBtn.disabled = !enabled;
    }
    async function copyGeneratedCode(button) {
        if (!lastCode) return;
        const label = button.textContent;
        try {
            await navigator.clipboard.writeText(lastCode);
            button.textContent = 'Copied';
            setTimeout(() => { button.textContent = label; }, 1200);
        } catch {
            const ta = document.createElement('textarea');
            ta.value = lastCode;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                button.textContent = 'Copied';
                setTimeout(() => { button.textContent = label; }, 1200);
            } catch {
                showError('Could not copy to clipboard.');
            }
            document.body.removeChild(ta);
        }
    }
    function updateQuota(quota) {
        const el = document.getElementById('quota-text');
        if (!el || !quota) return;
        el.textContent = quota.unlimited ? 'Quota: unlimited' : `Quota: ${quota.used}/${quota.limit} today`;
    }
    function renderResult(payload) {
        const notes = payload.notes || payload.summary || '';
        const code = payload.code || payload.improved_code || '';
        lastCode = code;
        setCopyEnabled(!!code);
        let html = '';
        if (payload.mode === 'modify') {
            html += `<p class="summary">Modified file</p>`;
        }
        if (notes) html += `<p class="summary">${escapeHtml(notes)}</p>`;
        if (code) {
            html += `<div class="code-wrap">
                <button type="button" class="btn-copy-inline" id="copy-inline-btn">Copy</button>
                <pre class="code-block" id="generated-code">${escapeHtml(code)}</pre>
            </div>`;
        } else {
            html = `<p class="empty">No code returned.</p>`;
        }
        resultBox.innerHTML = html;
        const inlineBtn = document.getElementById('copy-inline-btn');
        if (inlineBtn) inlineBtn.addEventListener('click', () => copyGeneratedCode(inlineBtn));
        metaBox.style.display = 'block';
        latencyText.textContent = `Latency: ${Number(payload.latency_ms||0).toLocaleString()} ms`;
        modelText.innerHTML = payload.model ? `<br>Model: ${escapeHtml(payload.model)}` : '';
        if (payload.review_run_id) {
            modelText.innerHTML += `<br><a href="/history/${payload.review_run_id}">Open in history</a>`;
        }
    }

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
    copyBtn.addEventListener('click', () => copyGeneratedCode(copyBtn));
    copyResultBtn.addEventListener('click', () => copyGeneratedCode(copyResultBtn));
    iterateBtn.addEventListener('click', () => {
        if (!lastCode) return;
        sourceInput.value = lastCode;
        setMode('modify');
        instructionInput.value = '';
        instructionInput.focus();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();
        abortController = new AbortController();
        btn.disabled = true;
        cancelBtn.disabled = false;
        setCopyEnabled(false);
        iterateBtn.disabled = true;
        loading.classList.add('show');
        progressLine.classList.add('show');
        progressLine.textContent = 'Progress: 0%';
        rawStreamWrap.style.display = 'block';
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
                throw new Error(text || `Stream failed (${response.status})`);
            }
            let finished = false;
            await parseSseStream(response, (eventName, data) => {
                if (eventName === 'token') {
                    streamBox.textContent += data.content || '';
                    streamBox.scrollTop = streamBox.scrollHeight;
                } else if (eventName === 'progress') {
                    progressLine.textContent = `Progress: ${data.percent ?? 0}%`;
                } else if (eventName === 'error') {
                    finished = true;
                    showError(data.detail || 'Generate failed.');
                } else if (eventName === 'done') {
                    finished = true;
                    window.__lastGeneratePayload = data;
                    renderResult(data);
                } else if (eventName === 'meta') {
                    updateQuota(data.quota);
                    if (window.__lastGeneratePayload && data.review_run_id) {
                        window.__lastGeneratePayload.review_run_id = data.review_run_id;
                        renderResult(window.__lastGeneratePayload);
                    }
                    if (data.secrets_redacted) {
                        streamBox.textContent += `\n[note] Redacted ${data.secrets_redacted} secret-like value(s).\n`;
                    }
                }
            });
            if (!finished) showError('Stream ended before generation completed.');
        } catch (err) {
            if (err.name === 'AbortError') showError('Generation cancelled.');
            else showError(err.message || 'Generate request failed.');
        } finally {
            btn.disabled = false;
            cancelBtn.disabled = true;
            loading.classList.remove('show');
            abortController = null;
        }
    });
</script>
@endsection
