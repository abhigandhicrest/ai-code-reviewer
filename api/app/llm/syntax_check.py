"""Best-effort syntax validation for generated code.

Each checker shells out to a real parser/compiler. When the tool is not
installed (or the language has no reliable checker), the code is treated as
unchecked and generation proceeds normally.
"""

from __future__ import annotations

import os
import shutil
import subprocess
import tempfile

_TIMEOUT_SECONDS = 20
_MAX_DETAIL = 1200


def check_syntax(code: str, language: str) -> tuple[bool, str | None]:
    """Return (ok, error_detail). Missing tools => (True, None)."""
    lang = (language or "").lower().strip()
    handler = _HANDLERS.get(lang)
    if handler is None or not code.strip():
        return True, None
    return handler(code)


def _with_temp_file(code: str, suffix: str, fn) -> tuple[bool, str | None]:
    fd, path = tempfile.mkstemp(suffix=suffix, prefix="acr_gen_")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            handle.write(code)
        return fn(path)
    except (OSError, subprocess.SubprocessError) as exc:
        return True, None
    finally:
        try:
            os.unlink(path)
        except OSError:
            pass


def _run(cmd: list[str], path: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        [*cmd, path],
        capture_output=True,
        text=True,
        timeout=_TIMEOUT_SECONDS,
    )


def _detail(proc: subprocess.CompletedProcess) -> str:
    text = (proc.stderr or proc.stdout or "").strip()
    return text[:_MAX_DETAIL] or f"checker exited with code {proc.returncode}"


def _check_php(code: str) -> tuple[bool, str | None]:
    if not shutil.which("php"):
        return True, None

    def run(path: str) -> tuple[bool, str | None]:
        try:
            proc = _run(["php", "-l"], path)
        except (OSError, subprocess.SubprocessError):
            return True, None
        # php -l exits 0 on success; parse errors may appear on stdout or stderr.
        output = (proc.stdout or "") + (proc.stderr or "")
        if proc.returncode == 0 and "No syntax errors detected" in output:
            return True, None
        if proc.returncode != 0:
            return False, _detail(proc)
        return True, None

    return _with_temp_file(code, ".php", run)


def _check_python(code: str) -> tuple[bool, str | None]:
    # In-process compile(): no subprocess, no __pycache__ artifacts.
    try:
        compile(code, "<generated>", "exec")
    except SyntaxError as exc:
        return False, f"{exc.msg} (line {exc.lineno})"
    return True, None


def _check_javascript(code: str) -> tuple[bool, str | None]:
    if not shutil.which("node"):
        return True, None

    def run(path: str) -> tuple[bool, str | None]:
        try:
            proc = _run(["node", "--check"], path)
        except (OSError, subprocess.SubprocessError):
            return True, None
        if proc.returncode == 0:
            return True, None
        return False, _detail(proc)

    return _with_temp_file(code, ".js", run)


def _check_go(code: str) -> tuple[bool, str | None]:
    # gofmt parses the file; -e prints parse errors. Formatting differences do
    # not affect the exit code, so this is effectively a syntax check.
    if not shutil.which("gofmt"):
        return True, None

    def run(path: str) -> tuple[bool, str | None]:
        try:
            proc = _run(["gofmt", "-e"], path)
        except (OSError, subprocess.SubprocessError):
            return True, None
        if proc.returncode == 0:
            return True, None
        return False, _detail(proc)

    return _with_temp_file(code, ".go", run)


def _check_rust(code: str) -> tuple[bool, str | None]:
    if not shutil.which("rustfmt"):
        return True, None

    def run(path: str) -> tuple[bool, str | None]:
        try:
            proc = _run(["rustfmt", "--check"], path)
        except (OSError, subprocess.SubprocessError):
            return True, None
        # rustfmt exits 1 both for parse errors and for formatting diffs.
        # Parse errors are reported on stderr; diffs go to stdout.
        if proc.returncode != 0 and "error" in (proc.stderr or "").lower():
            return False, _detail(proc)
        return True, None

    return _with_temp_file(code, ".rs", run)


_HANDLERS = {
    "php": _check_php,
    "python": _check_python,
    "py": _check_python,
    "javascript": _check_javascript,
    "js": _check_javascript,
    "jsx": _check_javascript,
    "mjs": _check_javascript,
    "cjs": _check_javascript,
    "go": _check_go,
    "rust": _check_rust,
    "rs": _check_rust,
}
