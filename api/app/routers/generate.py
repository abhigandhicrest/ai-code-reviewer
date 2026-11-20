from __future__ import annotations

import asyncio
import json
from typing import Any, AsyncIterator

from fastapi import APIRouter
from fastapi.responses import StreamingResponse

from app.config import settings
from app.llm.client import llm_client
from app.llm.generate_prompts import (
    GENERATE_JSON_SCHEMA,
    build_generate_prompt,
    build_modify_prompt,
    build_repair_prompt,
)
from app.llm.parse import parse_generate_text
from app.llm.sizing import estimate_tokens
from app.llm.syntax_check import check_syntax
from app.schemas import GenerateRequest, GenerateResponse

router = APIRouter()

_SYSTEM_PROMPT = "You are an expert software engineer. Follow the requested output format exactly."
_JSON_REMINDER = (
    "\n\nIMPORTANT: Your previous response was not valid JSON. "
    "Respond with ONLY one JSON object — no markdown fences, no text outside the JSON."
)
_TRUNCATION_MARKER = "\n\n[... truncated to fit the local model context ...]\n\n"


def _sse(event: str, data: dict[str, Any]) -> str:
    return f"event: {event}\ndata: {json.dumps(data, ensure_ascii=False)}\n\n"


def _truncate_to_tokens(text: str, allowed_tokens: int) -> str:
    """Head+tail truncate text to roughly allowed_tokens (4 chars/token estimate)."""
    if not text:
        return text
    if estimate_tokens(text) <= allowed_tokens:
        return text
    target_chars = max(256, allowed_tokens * 4)
    head = int(target_chars * 0.7)
    tail = max(0, target_chars - head)
    return text[:head] + _TRUNCATION_MARKER + (text[-tail:] if tail else "")


def _budget_inputs(
    instruction: str,
    context: str | None,
    source_code: str | None,
) -> tuple[str | None, str | None]:
    """Fit instruction + context + source_code into the model context window.

    source_code (modify mode) gets priority — up to 60% of the remaining budget —
    then context fills what's left.
    """
    total_budget = (
        settings.llm_context_size
        - settings.llm_generate_max_tokens
        - settings.llm_prompt_overhead_tokens
    )
    remaining = max(512, total_budget - estimate_tokens(instruction) - 256)

    if source_code:
        source_code = _truncate_to_tokens(source_code, int(remaining * 0.6))
        remaining = max(256, remaining - estimate_tokens(source_code))

    if context:
        context = _truncate_to_tokens(context, remaining)

    return context, source_code


def _build_prompt(payload: GenerateRequest, context: str | None, source_code: str | None) -> str:
    kwargs = {
        "context": context,
        "profile_instructions": payload.profile_instructions,
    }
    if source_code:
        return build_modify_prompt(
            payload.instruction, payload.language, source_code, **kwargs
        )
    return build_generate_prompt(payload.instruction, payload.language, **kwargs)


async def _repair_if_needed(
    code: str, language: str, notes: str
) -> tuple[str, str, str]:
    """Syntax-check generated code; on failure run one LLM repair pass.

    Returns (code, notes, status) where status is "ok" | "repaired" | "failed".
    """
    if not code:
        return code, notes, "ok"
    ok, error = await asyncio.to_thread(check_syntax, code, language)
    if ok:
        return code, notes, "ok"

    try:
        result = await llm_client.chat(
            build_repair_prompt(code, language, error or "syntax error"),
            temperature=0.1,
            max_tokens=settings.llm_generate_max_tokens,
            system=_SYSTEM_PROMPT,
            response_format=GENERATE_JSON_SCHEMA,
        )
        parsed = parse_generate_text(result["review"])
        fixed = (parsed.get("code") or "").strip()
        if fixed:
            ok2, _ = await asyncio.to_thread(check_syntax, fixed, language)
            if ok2:
                fix_notes = (parsed.get("notes") or "").strip()
                merged = (notes + " " if notes else "") + "[auto-repaired syntax errors]"
                if fix_notes:
                    merged += f" — {fix_notes}"
                return fixed, merged, "repaired"
    except Exception:
        pass

    warn = "[warning: generated code failed syntax check — review before using]"
    return code, (notes + " " if notes else "") + warn, "failed"


async def _chat_parsed(prompt: str):
    """Call the LLM and parse generate output; retry once if not JSON."""
    result = await llm_client.chat(
        prompt,
        temperature=settings.llm_generate_temperature,
        max_tokens=settings.llm_generate_max_tokens,
        system=_SYSTEM_PROMPT,
        response_format=GENERATE_JSON_SCHEMA,
    )
    parsed = parse_generate_text(result["review"])
    if not parsed["parsed"]:
        result = await llm_client.chat(
            prompt + _JSON_REMINDER,
            temperature=settings.llm_generate_temperature,
            max_tokens=settings.llm_generate_max_tokens,
            system=_SYSTEM_PROMPT,
            response_format=GENERATE_JSON_SCHEMA,
        )
        parsed = parse_generate_text(result["review"])
    return result, parsed


@router.post("/api/v1/generate", response_model=GenerateResponse)
async def generate_code(payload: GenerateRequest) -> GenerateResponse:
    source_code = (payload.source_code or "").strip() or None
    context, source_code = _budget_inputs(payload.instruction, payload.context, source_code)
    prompt = _build_prompt(payload, context, source_code)
    result, parsed = await _chat_parsed(prompt)
    code = parsed["code"] or result["review"]
    code, notes, _status = await _repair_if_needed(code, payload.language, parsed["notes"])
    return GenerateResponse(
        notes=notes,
        code=code,
        model=result["model"],
        latency_ms=int(result["latency_ms"]),
        parsed=bool(parsed["parsed"]),
        language=payload.language,
    )


@router.post("/api/v1/generate/stream")
async def generate_code_stream(payload: GenerateRequest) -> StreamingResponse:
    async def event_generator() -> AsyncIterator[str]:
        is_modify = bool(payload.source_code and payload.source_code.strip())
        yield _sse("progress", {"phase": "start", "percent": 5})
        yield _sse(
            "token",
            {"content": "[note] Modifying code…\n" if is_modify else "[note] Generating code…\n"},
        )

        source_code = (payload.source_code or "").strip() or None
        context, source_code = _budget_inputs(payload.instruction, payload.context, source_code)
        prompt = _build_prompt(payload, context, source_code)

        raw = ""
        model = "unknown"
        latency_ms = 0

        async for event in llm_client.chat_stream(
            prompt,
            temperature=settings.llm_generate_temperature,
            max_tokens=settings.llm_generate_max_tokens,
            system=_SYSTEM_PROMPT,
            response_format=GENERATE_JSON_SCHEMA,
        ):
            if event["type"] == "token":
                yield _sse("token", {"content": event["content"]})
            elif event["type"] == "error":
                yield _sse("error", {"detail": event["detail"]})
                return
            elif event["type"] == "done":
                raw = event.get("review") or ""
                model = event.get("model") or "unknown"
                latency_ms = int(event.get("latency_ms") or 0)

        parsed = parse_generate_text(raw)
        if not parsed["parsed"]:
            yield _sse("token", {"content": "\n[note] Retrying with stricter JSON format…\n"})
            retry_result, retry_parsed = await _chat_parsed(prompt)
            if retry_parsed["parsed"]:
                parsed = retry_parsed
                latency_ms += int(retry_result.get("latency_ms") or 0)

        code = parsed["code"] or raw
        notes = parsed["notes"]

        code, notes, status = await _repair_if_needed(code, payload.language, notes)
        if status == "repaired":
            yield _sse("token", {"content": "\n[note] Syntax errors auto-repaired.\n\n"})
        elif status == "failed":
            yield _sse("token", {"content": "\n[note] Generated code failed syntax check.\n\n"})

        yield _sse("progress", {"phase": "done", "percent": 100})
        yield _sse(
            "done",
            {
                "notes": notes,
                "code": code,
                "summary": notes,
                "improved_code": code,
                "findings": [],
                "model": model,
                "latency_ms": latency_ms,
                "parsed": bool(parsed["parsed"]),
                "language": payload.language,
                "mode": "modify" if is_modify else "generate",
                "files_count": 1,
                "chunks_count": 1,
            },
        )

    return StreamingResponse(event_generator(), media_type="text/event-stream")
