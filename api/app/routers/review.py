import json
from typing import Any, AsyncIterator, List, Optional

from fastapi import APIRouter, HTTPException
from fastapi.responses import StreamingResponse

from app.config import settings
from app.llm.chunking import chunk_budget_tokens, split_code_into_chunks
from app.llm.client import LlmUnavailableError, llm_client
from app.llm.parse import parse_review_text
from app.llm.prompts import build_review_prompt
from app.schemas import Finding, HealthResponse, ReviewFile, ReviewRequest, ReviewResponse, Severity

router = APIRouter()


def _severity_rank(severity: Severity) -> int:
    return {Severity.high: 0, Severity.medium: 1, Severity.low: 2}.get(severity, 3)


def _dedupe_findings(findings: List[Finding]) -> List[Finding]:
    seen: set[str] = set()
    unique: list[Finding] = []
    for finding in sorted(findings, key=lambda f: (_severity_rank(f.severity), f.file_path or "", f.title)):
        key = (
            f"{finding.severity.value}|{finding.file_path or ''}|"
            f"{finding.title.strip().lower()}|{finding.explanation.strip().lower()[:120]}"
        )
        if key in seen:
            continue
        seen.add(key)
        unique.append(finding)
    return unique


def _normalize_files(payload: ReviewRequest) -> list[ReviewFile]:
    if payload.files:
        return payload.files
    return [
        ReviewFile(
            path="snippet." + (payload.language or "txt"),
            language=payload.language or "other",
            code=payload.code or "",
        )
    ]


def _merge_parts(parts: list[dict[str, Any]], model: str, latency_ms: int, files_count: int) -> ReviewResponse:
    summaries: list[str] = []
    findings: list[Finding] = []
    improved_parts: list[str] = []
    parsed_any = False
    review_parts: list[str] = []
    file_results: list[dict[str, Any]] = []
    total_chunks = 0

    by_file: dict[str, dict[str, Any]] = {}

    for part in parts:
        parsed_any = parsed_any or bool(part.get("parsed"))
        total_chunks += int(part.get("chunks_used") or 1)
        path = part.get("file_path") or "snippet"
        file_entry = by_file.setdefault(
            path,
            {
                "path": path,
                "language": part.get("language") or "other",
                "summary": "",
                "findings": [],
                "chunks_count": 0,
                "improved_code": "",
            },
        )
        file_entry["chunks_count"] += int(part.get("chunks_used") or 1)

        summary = (part.get("summary") or "").strip()
        if summary:
            label = part.get("chunk_label") or ""
            summaries.append(f"{label}{summary}".strip())
            if file_entry["summary"]:
                file_entry["summary"] += " " + summary
            else:
                file_entry["summary"] = summary

        for finding in part.get("findings") or []:
            if isinstance(finding, Finding):
                findings.append(finding)
                file_entry["findings"].append(finding.model_dump())

        improved = (part.get("improved_code") or "").strip()
        if improved:
            if file_entry["improved_code"]:
                file_entry["improved_code"] += "\n\n" + improved
            else:
                file_entry["improved_code"] = improved
            improved_parts.append(f"### {path}\n{improved}")

        review_text = (part.get("review") or "").strip()
        if review_text:
            review_parts.append(review_text)

    findings = _dedupe_findings(findings)
    for entry in by_file.values():
        if not entry.get("improved_code"):
            entry["improved_code"] = None
        file_results.append(entry)

    summary = " ".join(summaries).strip()
    improved_code = "\n\n".join(improved_parts).strip() or None
    review = "\n\n".join(review_parts).strip()
    if not review:
        bits = []
        if summary:
            bits.append(summary)
        for finding in findings:
            loc = f" ({finding.file_path})" if finding.file_path else ""
            bits.append(f"[{finding.severity.value.upper()}]{loc} {finding.title}: {finding.explanation}")
        if improved_code:
            bits.append("Improved implementation:\n" + improved_code)
        review = "\n\n".join(bits)

    return ReviewResponse(
        review=review,
        summary=summary,
        findings=findings,
        improved_code=improved_code,
        model=model,
        latency_ms=latency_ms,
        parsed=parsed_any,
        chunks_count=max(1, total_chunks),
        files_count=files_count,
        file_results=file_results,
    )


def _locate_finding(finding: Finding, file_path: str, chunk) -> Finding:
    start = finding.start_line
    end = finding.end_line
    if start and chunk.start_line:
        start = chunk.start_line + start - 1
        end = (chunk.start_line + end - 1) if end else start
    if start and end and end < start:
        end = start
    if start:
        hint = f"line {start}" if not end or end == start else f"lines {start}-{end}"
        if finding.line_hint and "line" not in finding.line_hint.lower():
            hint = f"{hint} / {finding.line_hint}"
    else:
        hint = finding.line_hint
    return Finding(
        severity=finding.severity,
        title=finding.title,
        explanation=finding.explanation,
        line_hint=hint,
        file_path=file_path,
        start_line=start,
        end_line=end or start,
    )


def _standards_for_language(
    standards_by_language: Optional[dict[str, str]],
    language: str,
) -> Optional[str]:
    if not standards_by_language:
        return None
    lang = (language or "").lower().strip()
    aliases = {
        "js": "javascript",
        "jsx": "javascript",
        "ts": "typescript",
        "tsx": "typescript",
        "py": "python",
    }
    key = aliases.get(lang, lang)
    if key in standards_by_language and standards_by_language[key].strip():
        return standards_by_language[key]
    if "general" in standards_by_language and standards_by_language["general"].strip():
        return standards_by_language["general"]
    return None


async def _review_one_file(
    file: ReviewFile,
    *,
    focus,
    profile_instructions: Optional[str],
    generate_code: bool = True,
    review_mode: str = "full",
    standards_by_language: Optional[dict[str, str]] = None,
    on_note=None,
) -> tuple[list[dict[str, Any]], str, int]:
    max_chunk_tokens = chunk_budget_tokens(
        settings.llm_context_size,
        settings.llm_max_tokens,
        settings.llm_prompt_overhead_tokens,
    )
    chunks = split_code_into_chunks(
        file.code,
        max_chunk_tokens=max_chunk_tokens,
        max_chunks=6,
    )

    parts: list[dict[str, Any]] = []
    model = "unknown"
    total_latency = 0

    if on_note and len(chunks) > 1:
        await on_note(
            f"[note] {file.path}: reviewing in {len(chunks)} chunks…\n"
        )

    for chunk in chunks:
        label = ""
        if chunk.total > 1:
            label = (
                f"[{file.path} chunk {chunk.index}/{chunk.total} "
                f"lines {chunk.start_line}-{chunk.end_line}] "
            )
            if on_note:
                await on_note(f"[note] Reviewing {label.strip()}…\n")

        prompt = build_review_prompt(
            code=chunk.text,
            language=file.language,
            focus=focus,
            chunk_index=chunk.index,
            chunk_total=chunk.total,
            start_line=chunk.start_line,
            end_line=chunk.end_line,
            file_path=file.path,
            profile_instructions=profile_instructions,
            generate_code=generate_code,
            review_mode=review_mode,
            coding_standards=_standards_for_language(standards_by_language, file.language),
        )

        result = await llm_client.chat(prompt)
        model = result["model"]
        total_latency += int(result["latency_ms"])
        parsed = parse_review_text(result["review"])

        findings: list[Finding] = []
        for finding in parsed["findings"]:
            findings.append(_locate_finding(finding, file.path, chunk))

        parts.append(
            {
                "summary": parsed["summary"],
                "findings": findings,
                "improved_code": parsed["improved_code"],
                "review": parsed["review"],
                "parsed": parsed["parsed"],
                "chunk_label": label,
                "file_path": file.path,
                "language": file.language,
                "chunks_used": 1,
            }
        )

    # Collapse per-file chunk parts into one logical contribution with chunk count.
    if parts:
        parts[0]["chunks_used"] = len(chunks)
        for extra in parts[1:]:
            extra["chunks_used"] = 0

    return parts, model, total_latency


@router.get("/health", response_model=HealthResponse)
async def health() -> HealthResponse:
    status = await llm_client.health()
    return HealthResponse(
        status="ok",
        llm=status["llm"] or "down",
        model=status.get("model"),
        detail=status.get("detail"),
    )


@router.post("/api/v1/review", response_model=ReviewResponse)
async def review_code(payload: ReviewRequest) -> ReviewResponse:
    files = _normalize_files(payload)
    all_parts: list[dict[str, Any]] = []
    model = "unknown"
    total_latency = 0

    try:
        for index, file in enumerate(files, start=1):
            parts, model, latency = await _review_one_file(
                file,
                focus=payload.focus,
                profile_instructions=payload.profile_instructions,
                generate_code=bool(payload.generate_code),
                review_mode=payload.review_mode or "full",
                standards_by_language=payload.standards_by_language,
            )
            all_parts.extend(parts)
            total_latency += latency
    except LlmUnavailableError as exc:
        raise HTTPException(status_code=503, detail=exc.message) from exc

    return _merge_parts(all_parts, model, total_latency, len(files))


@router.post("/api/v1/review/stream")
async def review_code_stream(payload: ReviewRequest) -> StreamingResponse:
    files = _normalize_files(payload)

    async def event_generator() -> AsyncIterator[str]:
        try:
            all_parts: list[dict[str, Any]] = []
            model = "unknown"
            total_latency = 0
            total_files = len(files)

            for file_index, file in enumerate(files, start=1):
                yield _sse(
                    "progress",
                    {
                        "phase": "file",
                        "file_index": file_index,
                        "files_total": total_files,
                        "path": file.path,
                        "percent": int(((file_index - 1) / max(total_files, 1)) * 100),
                    },
                )
                yield _sse(
                    "token",
                    {"content": f"[note] File {file_index}/{total_files}: {file.path}\n"},
                )

                max_chunk_tokens = chunk_budget_tokens(
                    settings.llm_context_size,
                    settings.llm_max_tokens,
                    settings.llm_prompt_overhead_tokens,
                )
                chunks = split_code_into_chunks(
                    file.code,
                    max_chunk_tokens=max_chunk_tokens,
                    max_chunks=6,
                )

                file_parts: list[dict[str, Any]] = []
                for chunk in chunks:
                    label = ""
                    if chunk.total > 1:
                        label = (
                            f"[{file.path} chunk {chunk.index}/{chunk.total} "
                            f"lines {chunk.start_line}-{chunk.end_line}] "
                        )
                        yield _sse("token", {"content": f"[note] Reviewing {label.strip()}…\n"})
                        yield _sse(
                            "progress",
                            {
                                "phase": "chunk",
                                "file_index": file_index,
                                "files_total": total_files,
                                "chunk_index": chunk.index,
                                "chunks_total": chunk.total,
                                "path": file.path,
                                "percent": int(
                                    (
                                        ((file_index - 1) + ((chunk.index - 1) / max(chunk.total, 1)))
                                        / max(total_files, 1)
                                    )
                                    * 100
                                ),
                            },
                        )

                    prompt = build_review_prompt(
                        code=chunk.text,
                        language=file.language,
                        focus=payload.focus,
                        chunk_index=chunk.index,
                        chunk_total=chunk.total,
                        start_line=chunk.start_line,
                        end_line=chunk.end_line,
                        file_path=file.path,
                        profile_instructions=payload.profile_instructions,
                        generate_code=bool(payload.generate_code),
                        review_mode=payload.review_mode or "full",
                        coding_standards=_standards_for_language(
                            payload.standards_by_language,
                            file.language,
                        ),
                    )

                    async for event in llm_client.chat_stream(prompt):
                        if event["type"] == "token":
                            yield _sse("token", {"content": event["content"]})
                        elif event["type"] == "error":
                            yield _sse("error", {"detail": event["detail"]})
                            return
                        elif event["type"] == "done":
                            model = event["model"]
                            total_latency += int(event["latency_ms"])
                            parsed = parse_review_text(event["review"])
                            findings: list[Finding] = []
                            for finding in parsed["findings"]:
                                findings.append(_locate_finding(finding, file.path, chunk))
                            file_parts.append(
                                {
                                    "summary": parsed["summary"],
                                    "findings": findings,
                                    "improved_code": parsed["improved_code"],
                                    "review": parsed["review"],
                                    "parsed": parsed["parsed"],
                                    "chunk_label": label,
                                    "file_path": file.path,
                                    "language": file.language,
                                    "chunks_used": 1 if chunk.index == 1 else 0,
                                }
                            )

                if file_parts:
                    file_parts[0]["chunks_used"] = len(chunks)
                all_parts.extend(file_parts)
                yield _sse("token", {"content": f"\n[note] Finished {file.path}.\n\n"})

            structured = _merge_parts(all_parts, model, total_latency, total_files)
            yield _sse("progress", {"phase": "done", "percent": 100})
            yield _sse("done", structured.model_dump())
        except LlmUnavailableError as exc:
            yield _sse("error", {"detail": exc.message})

    return StreamingResponse(
        event_generator(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",
        },
    )


def _sse(event: str, data: dict[str, Any]) -> str:
    return f"event: {event}\ndata: {json.dumps(data, ensure_ascii=False)}\n\n"
