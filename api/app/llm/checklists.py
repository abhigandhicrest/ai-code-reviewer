from __future__ import annotations


def checklist_for_language(language: str) -> str:
    lang = (language or "").lower().strip()

    if lang in {"php"} or "blade" in lang:
        return (
            "Language checklist (PHP/Blade): Blade escaping vs {!! !!}; SQL injection; "
            "mass assignment; auth/gates/policies; CSRF; file upload validation; "
            "N+1 Eloquent queries; unsafe unserialize; path traversal."
        )

    if lang in {"python", "py"}:
        return (
            "Language checklist (Python): injection via subprocess/shell=True; "
            "pickle/yaml.load risks; path traversal; SSRF; race conditions; "
            "exception swallowing; mutable default args; resource leaks."
        )

    if lang in {"javascript", "typescript", "js", "ts", "jsx", "tsx"}:
        return (
            "Language checklist (JS/TS): XSS/DOM sinks; prototype pollution; "
            "unsafe eval; CSRF on cookie auth; dependency confusion; "
            "async error handling; path traversal in fs APIs."
        )

    return (
        "Language checklist (general): input validation; authz mistakes; "
        "secret leakage; injection; error handling; resource cleanup."
    )
