<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'AI Code Reviewer')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Sora:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('acr-theme');
                var theme = stored === 'light' || stored === 'dark'
                    ? stored
                    : (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    <style>
        :root, [data-theme="light"] {
            --bg: #eef2f7;
            --bg-glow: #d7e4f5;
            --panel: #ffffff;
            --panel-soft: #f5f8fc;
            --border: #cfd9e6;
            --text: #152033;
            --muted: #5b6b82;
            --accent: #0b6bcb;
            --accent-hover: #0958a8;
            --accent-soft: color-mix(in srgb, var(--accent) 12%, transparent);
            --ok: #1f9d63;
            --bad: #d64550;
            --warn: #b78103;
            --high: #d64550;
            --medium: #b78103;
            --low: #2f6fed;
            --input-bg: #f8fafc;
            --code-bg: #f1f5fa;
            --nav-bg: color-mix(in srgb, var(--panel) 88%, white);
            --shadow: 0 10px 30px rgba(21, 32, 51, 0.06);
            --shadow-sm: 0 1px 2px rgba(21, 32, 51, 0.04);
            --error-bg: color-mix(in srgb, var(--bad) 10%, var(--panel));
            --error-text: #8f1f2a;
            --sev-high-fg: #8f1f2a;
            --sev-med-fg: #7a5800;
            --sev-low-fg: #1e4fbf;
            --focus: 0 0 0 3px color-mix(in srgb, var(--accent) 28%, transparent);
            color-scheme: light;
        }

        [data-theme="dark"] {
            --bg: #0d1219;
            --bg-glow: #1a2740;
            --panel: #161e2b;
            --panel-soft: #121925;
            --border: #2a364a;
            --text: #e8eef7;
            --muted: #93a1b5;
            --accent: #4d9fff;
            --accent-hover: #7bb5ff;
            --accent-soft: color-mix(in srgb, var(--accent) 16%, transparent);
            --ok: #3dd68c;
            --bad: #f07178;
            --warn: #e6b450;
            --high: #f07178;
            --medium: #e6b450;
            --low: #7aa2f7;
            --input-bg: #0c1118;
            --code-bg: #0c1118;
            --nav-bg: color-mix(in srgb, var(--panel) 92%, black);
            --shadow: 0 12px 36px rgba(0, 0, 0, 0.28);
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.25);
            --error-bg: color-mix(in srgb, var(--bad) 12%, var(--panel));
            --error-text: #ffd0d3;
            --sev-high-fg: #ffc2c6;
            --sev-med-fg: #ffe2a0;
            --sev-low-fg: #c5d7ff;
            --focus: 0 0 0 3px color-mix(in srgb, var(--accent) 35%, transparent);
            color-scheme: dark;
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: "Sora", "Segoe UI", sans-serif;
            background:
                radial-gradient(1100px 520px at 8% -12%, var(--bg-glow) 0%, transparent 55%),
                radial-gradient(900px 420px at 100% 0%, color-mix(in srgb, var(--accent) 10%, transparent) 0%, transparent 45%),
                var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        a { color: var(--accent); text-decoration: none; transition: color .15s ease; }
        a:hover { color: var(--accent-hover); text-decoration: underline; }

        .wrap { max-width: 1180px; margin: 0 auto; padding: 1.15rem 1.25rem 3.5rem; }

        .topnav {
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
            flex-wrap: wrap; margin-bottom: 1.35rem;
            padding: .7rem .9rem .7rem 1rem;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--nav-bg);
            backdrop-filter: blur(10px);
            box-shadow: var(--shadow-sm);
        }
        .topnav .brand {
            font-weight: 700;
            color: var(--text);
            letter-spacing: -.02em;
            font-size: 1.02rem;
        }
        .topnav .brand:hover { text-decoration: none; color: var(--accent); }
        .topnav .links {
            display: flex; gap: .55rem .85rem; align-items: center; flex-wrap: wrap;
            color: var(--muted); font-size: .88rem;
        }
        .topnav .links > a {
            color: var(--muted);
            padding: .28rem .45rem;
            border-radius: 8px;
            text-decoration: none;
        }
        .topnav .links > a:hover {
            color: var(--text);
            background: var(--accent-soft);
            text-decoration: none;
        }
        .topnav form { display: inline; margin: 0; }
        .topnav button.linkish {
            appearance: none; border: 0; background: transparent; color: var(--accent);
            font: inherit; cursor: pointer; padding: .28rem .45rem; border-radius: 8px;
        }
        .topnav button.linkish:hover { background: var(--accent-soft); }

        .theme-toggle {
            appearance: none;
            border: 1px solid var(--border);
            background: var(--panel-soft);
            color: var(--text);
            width: 2.35rem;
            height: 2.35rem;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            transition: background .15s ease, border-color .15s ease, transform .15s ease;
        }
        .theme-toggle:hover {
            background: var(--accent-soft);
            border-color: color-mix(in srgb, var(--accent) 40%, var(--border));
        }
        .theme-toggle:active { transform: scale(.96); }
        .theme-toggle svg { width: 1.05rem; height: 1.05rem; display: none; }
        [data-theme="light"] .theme-toggle .icon-moon { display: block; }
        [data-theme="dark"] .theme-toggle .icon-sun { display: block; }

        h1 {
            margin: 0 0 .35rem;
            font-size: clamp(1.55rem, 2.4vw, 1.9rem);
            letter-spacing: -.03em;
            font-weight: 700;
        }
        .sub { color: var(--muted); margin: 0 0 1.25rem; max-width: 52rem; }

        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.05rem 1.15rem;
            box-shadow: var(--shadow-sm);
        }
        .error {
            border: 1px solid color-mix(in srgb, var(--bad) 45%, var(--border));
            background: var(--error-bg);
            color: var(--error-text);
            border-radius: 10px;
            padding: .85rem 1rem;
            margin-bottom: 1rem;
        }
        .flash-ok {
            border: 1px solid color-mix(in srgb, var(--ok) 40%, var(--border));
            background: color-mix(in srgb, var(--ok) 10%, var(--panel));
            color: var(--text);
            border-radius: 10px;
            padding: .85rem 1rem;
            margin-bottom: 1rem;
        }

        label { display: block; font-size: .86rem; color: var(--muted); margin-bottom: .4rem; font-weight: 500; }
        input[type="text"], input[type="email"], input[type="password"], input[type="number"], select, textarea, input[type="file"] {
            width: 100%;
            background: var(--input-bg);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: .7rem .8rem;
            font: inherit;
            margin-bottom: .85rem;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: color-mix(in srgb, var(--accent) 55%, var(--border));
            box-shadow: var(--focus);
        }
        textarea {
            font-family: "JetBrains Mono", ui-monospace, Consolas, monospace;
            font-size: .88rem;
            line-height: 1.45;
            resize: vertical;
        }

        button, .btn, a.btn {
            appearance: none; border: 0; border-radius: 10px;
            background: var(--accent); color: #fff; font-weight: 600;
            padding: .72rem 1.15rem; cursor: pointer; display: inline-block;
            font: inherit;
            text-decoration: none;
            transition: background .15s ease, transform .12s ease, opacity .15s ease;
        }
        button:hover, .btn:hover, a.btn:hover {
            background: var(--accent-hover);
            color: #fff;
            text-decoration: none;
        }
        button:active, .btn:active, a.btn:active { transform: translateY(1px); }
        button:disabled { opacity: .65; cursor: wait; }
        .btn-secondary, button.btn-secondary, a.btn-secondary {
            background: var(--panel-soft);
            color: var(--text);
            border: 1px solid var(--border);
        }
        .btn-secondary:hover, button.btn-secondary:hover, a.btn-secondary:hover {
            background: var(--accent-soft);
            border-color: color-mix(in srgb, var(--accent) 35%, var(--border));
            color: var(--text);
        }

        .auth-card { max-width: 420px; margin: 3rem auto; }
        .muted { color: var(--muted); }
        .hint { color: var(--muted); font-size: .8rem; margin: .35rem 0 .9rem; }
        .mono { font-family: "JetBrains Mono", ui-monospace, Consolas, monospace; }

        .stats {
            display: grid; gap: .75rem;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            margin-bottom: 1rem;
        }
        .stat {
            border: 1px solid var(--border); border-radius: 12px; padding: .85rem .9rem;
            background: var(--panel-soft);
        }
        .stat .n { font-size: 1.4rem; font-weight: 700; letter-spacing: -.02em; }
        .stat .l { color: var(--muted); font-size: .82rem; margin-top: .2rem; }

        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { text-align: left; padding: .6rem .45rem; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; font-size: .82rem; }
        tr:hover td { background: color-mix(in srgb, var(--accent) 4%, transparent); }
        .ok { color: var(--ok); }
        .bad { color: var(--bad); }
        .pill {
            display: inline-block; border-radius: 999px; padding: .12rem .55rem;
            font-size: .75rem; border: 1px solid var(--border); color: var(--muted);
            background: var(--panel-soft);
        }

        .badge {
            display: inline-flex; align-items: center; gap: .45rem; flex-wrap: wrap;
            border: 1px solid var(--border); background: var(--panel);
            border-radius: 999px; padding: .4rem .85rem; font-size: .85rem;
            color: var(--muted); margin-bottom: 1rem; box-shadow: var(--shadow-sm);
        }
        .dot { width: .55rem; height: .55rem; border-radius: 50%; background: var(--bad); flex-shrink: 0; }
        .dot.ok { background: var(--ok); }

        .grid { display: grid; gap: 1rem; }
        @media (min-width: 900px) {
            .grid.two { grid-template-columns: 1fr 1fr; align-items: start; }
        }
        .row { display: flex; gap: .75rem; flex-wrap: wrap; align-items: end; margin-bottom: .9rem; }
        .row > div { flex: 1; min-width: 140px; }
        .actions { display: flex; gap: .5rem; flex-wrap: wrap; align-items: end; }
        .meta { color: var(--muted); font-size: .85rem; margin-bottom: .75rem; word-break: break-all; }

        .loading, .progress-line {
            display: none; color: var(--warn); margin: .75rem 0 0; font-size: .95rem;
        }
        .loading.show, .progress-line.show { display: block; }

        .stream-box {
            display: none; margin-top: .75rem; max-height: 160px; overflow: auto;
            white-space: pre-wrap; font-family: "JetBrains Mono", ui-monospace, Consolas, monospace;
            font-size: .8rem; color: var(--muted); border: 1px dashed var(--border);
            border-radius: 10px; padding: .7rem .8rem; background: var(--code-bg);
        }
        .stream-box.show { display: block; }

        .summary { margin: 0 0 1rem; line-height: 1.55; }
        .finding {
            border: 1px solid var(--border); border-radius: 10px; padding: .75rem .85rem;
            margin-bottom: .65rem; background: var(--panel-soft);
        }
        .finding-head { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin-bottom: .35rem; }
        .sev {
            font-size: .72rem; font-weight: 700; text-transform: uppercase;
            border-radius: 999px; padding: .15rem .5rem;
        }
        .sev.high { background: color-mix(in srgb, var(--high) 22%, transparent); color: var(--sev-high-fg); }
        .sev.medium { background: color-mix(in srgb, var(--medium) 22%, transparent); color: var(--sev-med-fg); }
        .sev.low { background: color-mix(in srgb, var(--low) 22%, transparent); color: var(--sev-low-fg); }
        .finding h3 { margin: 0; font-size: .98rem; }
        .finding p { margin: .25rem 0 0; color: var(--muted); font-size: .9rem; }

        .code-block {
            margin-top: .75rem; white-space: pre-wrap;
            font-family: "JetBrains Mono", ui-monospace, Consolas, monospace;
            font-size: .85rem; background: var(--code-bg); border: 1px solid var(--border);
            border-radius: 10px; padding: .8rem; overflow: auto;
        }
        .empty { color: var(--muted); margin: 0; }

        .path-row { display: flex; gap: .5rem; align-items: end; flex-wrap: wrap; }
        .path-row input[type="text"] { flex: 1; min-width: 180px; margin-bottom: 0; }

        .file-picker {
            display: none; border: 1px solid var(--border); border-radius: 10px;
            background: var(--code-bg); padding: .6rem .7rem; margin: .5rem 0 .9rem;
            max-height: 220px; overflow: auto;
        }
        .file-picker.show { display: block; }
        .file-picker .picker-toolbar {
            display: flex; gap: .75rem; flex-wrap: wrap; align-items: center;
            margin-bottom: .5rem; font-size: .85rem; color: var(--muted);
        }
        .file-picker label.file-item {
            display: flex; gap: .5rem; align-items: flex-start; margin: 0 0 .35rem;
            color: var(--text); font-size: .85rem; cursor: pointer;
        }
        .file-picker label.file-item input { width: auto; margin: .15rem 0 0; }

        .result-head { display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap; margin-bottom: .35rem; }
        .result-head label { margin: 0; }
        .btn-copy, .btn-copy-inline {
            background: var(--panel-soft);
            color: var(--text);
            border: 1px solid var(--border);
            padding: .45rem .85rem;
            font-size: .85rem;
        }
        .code-wrap { position: relative; }
        .code-wrap .btn-copy-inline {
            position: absolute; top: .55rem; right: .55rem; z-index: 1;
            padding: .35rem .7rem; font-size: .8rem;
        }

        @media (max-width: 640px) {
            .wrap { padding: .85rem .85rem 2.5rem; }
            .topnav { padding: .65rem .75rem; }
            .topnav .links { gap: .35rem .55rem; font-size: .82rem; }
        }

        @yield('styles')
    </style>
</head>
<body>
<div class="wrap">
    <div class="topnav">
        <a class="brand" href="{{ route('review.index') }}">AI Code Reviewer</a>
        <div class="links">
            @auth
                <a href="{{ route('review.index') }}">Review</a>
                <a href="{{ route('generate.index') }}">Generate</a>
                <a href="{{ route('history.index') }}">History</a>
                <a href="{{ route('usage.index') }}">Usage</a>
                <a href="{{ route('health.status') }}">Health</a>
                @if(auth()->user()->is_admin)
                    <a href="{{ route('profiles.index') }}">Profiles</a>
                    <a href="{{ route('standards.index') }}">Standards</a>
                    <a href="{{ route('audit.index') }}">Audit</a>
                @endif
                <span class="muted">{{ auth()->user()->name }}@if(auth()->user()->is_admin) · admin @endif</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="linkish">Logout</button>
                </form>
            @else
                <a href="{{ route('login') }}">Login</a>
                <a href="{{ route('register') }}">Register</a>
            @endauth
            <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Toggle color theme" title="Toggle theme">
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="12" r="4"></circle>
                    <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
                </svg>
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M21 14.3A8.5 8.5 0 0 1 9.7 3 7 7 0 1 0 21 14.3z"></path>
                </svg>
            </button>
        </div>
    </div>
    @yield('content')
</div>
<script>
    (function () {
        var btn = document.getElementById('theme-toggle');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('acr-theme', next); } catch (e) {}
        });
    })();
</script>
@yield('scripts')
</body>
</html>
