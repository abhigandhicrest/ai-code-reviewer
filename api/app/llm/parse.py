from __future__ import annotations

import json
import re
from typing import Any

from app.schemas import Finding, Severity


_JSON_FENCE = re.compile(r"```(?:json)?\s*(\{.*?\})\s*```", re.DOTALL | re.IGNORECASE)


def _repair_json_string(text: str) -> str:
    """Escape raw control characters inside JSON string literals.

    Small local models often emit literal newlines/tabs inside string values,
    which is invalid JSON. Walk the text and escape control chars that appear
    between unescaped quotes; already-escaped sequences (\\n etc.) are preserved.
    """
    out: list[str] = []
    in_string = False
    escaped = False
    for ch in text:
        if in_string:
            if escaped:
                out.append(ch)
                escaped = False
            elif ch == "\\":
                out.append(ch)
                escaped = True
            elif ch == '"':
                out.append(ch)
                in_string = False
            elif ch == "\n":
                out.append("\\n")
            elif ch == "\r":
                out.append("\\r")
            elif ch == "\t":
                out.append("\\t")
            elif ord(ch) < 0x20:
                out.append(f"\\u{ord(ch):04x}")
            else:
                out.append(ch)
        else:
            out.append(ch)
            if ch == '"':
                in_string = True
    return "".join(out)


def _loads_lenient(text: str) -> dict[str, Any] | None:
    """json.loads with one repair pass for control chars inside strings."""
    try:
        data = json.loads(text)
        return data if isinstance(data, dict) else None
    except json.JSONDecodeError:
        pass
    try:
        data = json.loads(_repair_json_string(text))
        return data if isinstance(data, dict) else None
    except json.JSONDecodeError:
        return None


def _extract_json_object(text: str) -> dict[str, Any] | None:
    cleaned = text.strip()
    fence = _JSON_FENCE.search(cleaned)
    if fence:
        cleaned = fence.group(1)

    data = _loads_lenient(cleaned)
    if data:
        return data

    start = cleaned.find("{")
    end = cleaned.rfind("}")
    if start == -1 or end == -1 or end <= start:
        return None

    return _loads_lenient(cleaned[start : end + 1])


def _coerce_line(value: Any) -> int | None:
    if value is None or value == "":
        return None
    try:
        n = int(value)
    except (TypeError, ValueError):
        return _line_from_hint(value)
    return n if n > 0 else None


def _line_from_hint(value: Any) -> int | None:
    if value is None:
        return None
    text = str(value).strip()
    match = re.search(r"(?:lines?\s*)?(\d+)(?:\s*[-–to]+\s*(\d+))?", text, re.IGNORECASE)
    if not match:
        return None
    n = int(match.group(1))
    return n if n > 0 else None


def _normalize_severity(value: Any) -> Severity:
    raw = str(value or "medium").strip().lower()
    if raw in {"high", "critical", "error"}:
        return Severity.high
    if raw in {"low", "info", "nit"}:
        return Severity.low
    return Severity.medium


def parse_review_text(raw_text: str) -> dict[str, Any]:
    """Parse model text into structured review fields with safe fallbacks."""
    data = _extract_json_object(raw_text)
    if not data:
        return {
            "review": raw_text.strip(),
            "summary": "",
            "findings": [],
            "improved_code": None,
            "parsed": False,
        }

    findings: list[Finding] = []
    for item in data.get("findings") or []:
        if not isinstance(item, dict):
            continue
        title = str(item.get("title") or "").strip()
        explanation = str(item.get("explanation") or "").strip()
        if not title and not explanation:
            continue
        line_hint = item.get("line_hint")
        start_line = _coerce_line(item.get("start_line")) or _line_from_hint(line_hint)
        end_line = _coerce_line(item.get("end_line"))
        if start_line and end_line and end_line < start_line:
            end_line = start_line
        findings.append(
            Finding(
                severity=_normalize_severity(item.get("severity")),
                title=title or "Issue",
                explanation=explanation or title,
                line_hint=str(line_hint).strip() if line_hint else None,
                start_line=start_line,
                end_line=end_line or start_line,
            )
        )

    summary = str(data.get("summary") or "").strip()
    improved = data.get("improved_code")
    improved_code = str(improved).strip() if improved else None

    if not summary and findings:
        summary = findings[0].explanation

    review_parts: list[str] = []
    if summary:
        review_parts.append(summary)
    for finding in findings:
        review_parts.append(
            f"[{finding.severity.value.upper()}] {finding.title}: {finding.explanation}"
        )
    if improved_code:
        review_parts.append("Improved implementation:\n" + improved_code)

    return {
        "review": "\n\n".join(review_parts) if review_parts else raw_text.strip(),
        "summary": summary,
        "findings": findings,
        "improved_code": improved_code,
        "parsed": True,
    }


def _unescape_json_string(value: str) -> str:
    try:
        return json.loads(f'"{value}"')
    except json.JSONDecodeError:
        return value.replace('\\"', '"').replace("\\n", "\n").replace("\\t", "\t")


def _extract_generate_fields(text: str) -> dict[str, str] | None:
    """Last-resort field extraction for malformed generate JSON.

    Small models sometimes emit triple-quoted code blocks or strings containing
    literal newlines/quotes, which breaks json.loads even after repair.
    Pulls notes/code directly; returns None if no code field is found.
    """
    code = None
    triple = re.search(r'"code"\s*:\s*"""([\s\S]*)"""', text)
    if triple:
        code = triple.group(1).strip()
    else:
        single = re.search(r'"code"\s*:\s*"((?:[^"\\]|\\.)*)"', text, re.DOTALL)
        if single:
            code = _unescape_json_string(single.group(1)).strip()
    if code is None:
        return None

    notes = ""
    notes_m = re.search(r'"(?:notes|summary)"\s*:\s*"((?:[^"\\]|\\.)*)"', text, re.DOTALL)
    if notes_m:
        notes = _unescape_json_string(notes_m.group(1)).strip()

    inner = re.match(r"^```(?:\w+)?\s*([\s\S]*?)```$", code)
    if inner:
        code = inner.group(1).strip()

    return {"notes": notes, "code": code}


def parse_generate_text(raw_text: str) -> dict[str, Any]:
    """Parse model text into notes + generated code with safe fallbacks."""
    data = _extract_json_object(raw_text)
    if not data:
        fields = _extract_generate_fields(raw_text)
        if fields and fields["code"]:
            return {
                "notes": fields["notes"],
                "code": fields["code"],
                "parsed": True,
            }
        # Fallback: strip markdown fences if the model returned raw code.
        cleaned = raw_text.strip()
        fence = re.search(r"```(?:\w+)?\s*([\s\S]*?)```", cleaned)
        code = fence.group(1).strip() if fence else cleaned
        return {
            "notes": "",
            "code": code,
            "parsed": False,
        }

    notes = str(data.get("notes") or data.get("summary") or "").strip()
    code_raw = data.get("code")
    if code_raw is None:
        code_raw = data.get("improved_code") or data.get("generated_code") or ""
    code = str(code_raw).strip()
    # Strip accidental fences inside the code field.
    inner = re.match(r"^```(?:\w+)?\s*([\s\S]*?)```$", code)
    if inner:
        code = inner.group(1).strip()

    return {
        "notes": notes,
        "code": code,
        "parsed": True,
    }
