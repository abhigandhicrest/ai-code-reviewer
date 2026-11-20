#!/usr/bin/env python3
"""ai-buddy — CLI client for the local AI code reviewer.

Triggers the same review/generate pipeline as the web UI (Laravel API ->
FastAPI -> local CodeGemma) from the terminal. Runs are recorded in
history/quota exactly like web runs.

Usage (via the `ai-buddy` command):
    ai-buddy file.php [more files...]        # review files
    ai-buddy --staged                        # review `git diff --cached`
    ai-buddy --generate file.php             # review + improved code
    ai-buddy --instruction "..." --language php   # generate code
    ai-buddy --instruction "..." --source-code app.py  # modify code
    ai-buddy --staged --fail-on high         # exit 1 on high findings (CI)

Auth: token is read from AI_REVIEW_TOKEN or ~/.ai-review-token.
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

DEFAULT_BASE_URL = os.environ.get("AI_REVIEW_BASE_URL", "http://127.0.0.1:8000")
MAX_FILE_BYTES = 1_000_000  # matches REVIEW_MAX_FILE_BYTES on the web path

LANG_BY_EXT = {
    ".py": "python",
    ".js": "javascript",
    ".jsx": "javascript",
    ".ts": "typescript",
    ".tsx": "typescript",
    ".php": "php",
    ".java": "java",
    ".cs": "csharp",
    ".go": "go",
    ".rs": "rust",
    ".sql": "sql",
    ".rb": "other",
    ".c": "other",
    ".cpp": "other",
    ".h": "other",
    ".md": "other",
}

SEVERITY_RANK = {"low": 1, "medium": 2, "high": 3}


def detect_language(path: str) -> str:
    return LANG_BY_EXT.get(Path(path).suffix.lower(), "other")


def looks_like_diff(text: str) -> bool:
    head = text[:4000]
    return "diff --git" in head or "\n@@ " in head or head.startswith("--- ")


def read_stdin() -> str:
    if sys.stdin.isatty():
        return ""
    return sys.stdin.read()


def git_staged_diff(cwd: str) -> str:
    proc = subprocess.run(
        ["git", "diff", "--cached", "--no-color"],
        cwd=cwd,
        capture_output=True,
        text=True,
        timeout=60,
    )
    if proc.returncode != 0:
        raise SystemExit(f"git diff --cached failed: {proc.stderr.strip()}")
    return proc.stdout


def collect_files(paths: list[str], language: str | None) -> list[dict]:
    files = []
    for raw in paths:
        p = Path(raw)
        if not p.is_file():
            raise SystemExit(f"Not a file: {raw}")
        size = p.stat().st_size
        if size > MAX_FILE_BYTES:
            print(f"[warn] skipping {raw} ({size} bytes > {MAX_FILE_BYTES})", file=sys.stderr)
            continue
        code = p.read_text(encoding="utf-8", errors="replace")
        files.append(
            {
                "path": str(p),
                "language": language or detect_language(raw),
                "code": code,
            }
        )
    return files


def build_payload(args: argparse.Namespace) -> dict:
    payload: dict = {"generate_code": args.generate}

    if args.language:
        payload["language"] = args.language

    if args.staged:
        payload["diff_text"] = git_staged_diff(os.getcwd())
        return payload

    stdin_text = read_stdin()
    file_args = list(args.files)

    if stdin_text:
        if looks_like_diff(stdin_text):
            payload["diff_text"] = stdin_text
        else:
            payload["files"] = [
                {
                    "path": args.stdin_name,
                    "language": args.language or "other",
                    "code": stdin_text,
                }
            ]
        return payload

    if not file_args:
        raise SystemExit(
            "Nothing to review. Pass file paths, --staged, or pipe code/diff on stdin.\n"
            "Try: ai-buddy --help"
        )

    files = collect_files(file_args, args.language)
    if not files:
        raise SystemExit("No reviewable files (all skipped or empty).")
    payload["files"] = files
    if not args.language:
        payload["language"] = files[0]["language"]
    return payload


def print_human(data: dict, args: argparse.Namespace) -> None:
    parsed = data.get("parsed")
    parsed_txt = f"  |  Parsed: {parsed}" if parsed is not None else ""
    print(f"Model: {data.get('model', '?')}  |  Latency: {data.get('latency_ms', 0):,} ms{parsed_txt}")
    if data.get("summary"):
        print(f"\n{data['summary']}\n")

    findings = data.get("findings") or []
    if findings:
        order = {"high": 0, "medium": 1, "low": 2}
        findings = sorted(findings, key=lambda f: order.get(str(f.get("severity", "")).lower(), 3))
        print(f"Findings ({len(findings)}):")
        for f in findings:
            sev = str(f.get("severity", "?")).upper()
            loc = f.get("file_path") or ""
            hint = f.get("line_hint") or ""
            where = f" [{loc}{(':' + hint) if hint else ''}]" if loc or hint else ""
            print(f"  [{sev}] {f.get('title', '')}{where}")
            expl = (f.get("explanation") or "").strip()
            if expl:
                for line in expl.splitlines()[:4]:
                    print(f"        {line}")
    else:
        print("No findings.")

    improved = data.get("improved_code")
    if improved:
        print("\n----- improved_code -----")
        print(improved)

    if args.fail_on:
        worst = max(
            (SEVERITY_RANK.get(str(f.get("severity", "")).lower(), 0) for f in findings),
            default=0,
        )
        threshold = max(SEVERITY_RANK[s] for s in args.fail_on)
        if worst >= threshold:
            print(f"\nFAIL: findings at or above {sorted(args.fail_on)} severity.", file=sys.stderr)
            sys.exit(1)


def api_request(base_url: str, path: str, payload: dict, token: str, timeout: int) -> dict:
    """POST JSON to the Laravel API; exit(2) on transport/auth/HTTP errors."""
    url = base_url.rstrip("/") + path
    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            status = resp.status
            body = resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        status = e.code
        body = e.read().decode("utf-8", errors="replace")
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        print(f"Request failed: {e}", file=sys.stderr)
        sys.exit(2)

    if status in (401, 403):
        print("Auth failed — check AI_REVIEW_TOKEN / --token.", file=sys.stderr)
        sys.exit(2)
    if status == 422:
        print(f"Validation error: {body}", file=sys.stderr)
        sys.exit(2)
    if status >= 400:
        try:
            detail = json.loads(body).get("detail", body)
        except Exception:
            detail = body
        print(f"Request failed (HTTP {status}): {detail}", file=sys.stderr)
        sys.exit(2)

    try:
        return json.loads(body)
    except json.JSONDecodeError:
        print(f"Unexpected response: {body[:500]}", file=sys.stderr)
        sys.exit(2)


def print_generate(data: dict) -> None:
    parsed = data.get("parsed")
    parsed_txt = f"  |  Parsed: {parsed}" if parsed is not None else ""
    mode = data.get("mode") or "generate"
    print(f"Mode: {mode}  |  Model: {data.get('model', '?')}  |  Latency: {data.get('latency_ms', 0):,} ms{parsed_txt}")
    if data.get("notes"):
        print(f"\n{data['notes']}\n")
    code = data.get("code")
    if code:
        print("----- code -----")
        print(code)
    else:
        print("No code returned.")


def run_generate(args: argparse.Namespace) -> int:
    language = args.language
    source_code = None
    source_path = None
    if args.source_code:
        p = Path(args.source_code)
        if not p.is_file():
            print(f"Not a file: {args.source_code}", file=sys.stderr)
            return 2
        if p.stat().st_size > MAX_FILE_BYTES:
            print(f"Source file too large (> {MAX_FILE_BYTES} bytes).", file=sys.stderr)
            return 2
        source_code = p.read_text(encoding="utf-8", errors="replace")
        source_path = p
        if not language:
            language = detect_language(args.source_code)

    payload = {
        "instruction": args.instruction,
        "language": language or "python",
        "source_code": source_code,
    }
    data = api_request(args.base_url, "/api/v1/generate", payload, args.token, args.timeout)

    code = data.get("code") or ""
    # Decide where the code goes: explicit --output, else modify mode writes
    # back to the source file (with backup), else print to stdout.
    out_path = Path(args.output) if args.output else (source_path if source_path else None)
    wrote = False
    if out_path and code.strip():
        if out_path.exists():
            backup = out_path.with_name(out_path.name + ".bak")
            backup.write_text(out_path.read_text(encoding="utf-8", errors="replace"), encoding="utf-8")
            print(f"Backup saved: {backup}")
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(code, encoding="utf-8")
        wrote = True
        print(f"Wrote {len(code):,} chars to {out_path}")

    if args.json:
        print(json.dumps(data, indent=2, ensure_ascii=False))
    elif wrote:
        if data.get("notes"):
            print(f"\n{data['notes']}")
        print(f"Model: {data.get('model', '?')}  |  Latency: {data.get('latency_ms', 0):,} ms  |  Mode: {data.get('mode', 'generate')}")
    else:
        print_generate(data)
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(
        prog="ai-buddy",
        description="Review code with the local AI reviewer (Laravel API -> FastAPI -> CodeGemma).",
    )
    parser.add_argument("files", nargs="*", help="Files to review")
    parser.add_argument("--staged", action="store_true", help="Review `git diff --cached`")
    parser.add_argument("--diff", action="store_true", help="Read a unified diff from stdin")
    parser.add_argument("--stdin-name", default="stdin.txt", help="Path label for raw stdin code")
    parser.add_argument("--language", help="Override language (python, php, javascript, ...)")
    parser.add_argument("--generate", action="store_true", help="Request improved code")
    parser.add_argument(
        "--review",
        metavar="TEXT",
        help="Review a code snippet passed inline (combine with --language)",
    )
    parser.add_argument(
        "--instruction",
        help="Generate/modify code from an instruction instead of reviewing files",
    )
    parser.add_argument(
        "--source-code",
        metavar="FILE",
        help="Existing code file to modify (requires --instruction)",
    )
    parser.add_argument(
        "--output",
        metavar="FILE",
        help="Write generated code to FILE (default: modify mode writes back to --source-code)",
    )
    parser.add_argument(
        "--fail-on",
        help="Comma-separated severities that should exit non-zero (high,medium,low)",
    )
    parser.add_argument("--json", action="store_true", help="Print raw JSON response")
    parser.add_argument("--base-url", default=DEFAULT_BASE_URL, help="Laravel base URL")
    parser.add_argument("--token", default=os.environ.get("AI_REVIEW_TOKEN"), help="Sanctum token")
    parser.add_argument("--timeout", type=int, default=1800, help="HTTP timeout seconds")
    args = parser.parse_args()

    fail_on = None
    if args.fail_on:
        fail_on = [s.strip().lower() for s in args.fail_on.split(",") if s.strip()]
        bad = [s for s in fail_on if s not in SEVERITY_RANK]
        if bad:
            parser.error(f"Invalid --fail-on severity: {', '.join(bad)}")
        args.fail_on = fail_on

    if not args.token:
        print(
            "No API token. Create one with:\n"
            "  php artisan reviewer:token <your-email> --show\n"
            "then export AI_REVIEW_TOKEN=<token> or pass --token.",
            file=sys.stderr,
        )
        return 2

    if args.instruction:
        return run_generate(args)
    if args.source_code:
        print("--source-code requires --instruction.", file=sys.stderr)
        return 2

    if args.review is not None:
        lang = args.language or "other"
        payload = {
            "generate_code": args.generate,
            "language": lang,
            "files": [
                {"path": "review.txt", "language": lang, "code": args.review}
            ],
        }
    else:
        try:
            payload = build_payload(args)
        except SystemExit as e:
            print(e, file=sys.stderr)
            return 2

        if args.diff:
            payload = {"diff_text": sys.stdin.read(), "generate_code": args.generate}
            if args.language:
                payload["language"] = args.language

    data = api_request(args.base_url, "/api/v1/review", payload, args.token, args.timeout)
    if args.json:
        print(json.dumps(data, indent=2, ensure_ascii=False))
    else:
        print_human(data, args)
    return 0


if __name__ == "__main__":
    sys.exit(main())
