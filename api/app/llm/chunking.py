from __future__ import annotations

from dataclasses import dataclass

from app.llm.sizing import estimate_tokens


@dataclass
class CodeChunk:
    index: int
    total: int
    start_line: int
    end_line: int
    text: str


def split_code_into_chunks(
    code: str,
    *,
    max_chunk_tokens: int,
    max_chunks: int = 6,
) -> list[CodeChunk]:
    """
    Split source into overlapping line-based chunks that fit the model budget.
    Small files return a single chunk.
    """
    lines = code.splitlines(keepends=True)
    if not lines:
        return [CodeChunk(index=1, total=1, start_line=1, end_line=1, text=code)]

    if estimate_tokens(code) <= max_chunk_tokens:
        return [
            CodeChunk(
                index=1,
                total=1,
                start_line=1,
                end_line=len(lines),
                text=code,
            )
        ]

    chunks: list[CodeChunk] = []
    overlap = 8
    i = 0
    n = len(lines)

    while i < n and len(chunks) < max_chunks:
        start = i
        buf: list[str] = []
        tokens = 0
        while i < n:
            line = lines[i]
            line_tokens = estimate_tokens(line)
            if buf and tokens + line_tokens > max_chunk_tokens:
                break
            buf.append(line)
            tokens += line_tokens
            i += 1
            if tokens >= max_chunk_tokens:
                break

        if not buf:
            # Extremely long single line — hard cut by characters.
            line = lines[i]
            cut = max_chunk_tokens * 4
            buf = [line[:cut]]
            i += 1

        end = start + len(buf)
        chunks.append(
            CodeChunk(
                index=len(chunks) + 1,
                total=0,  # filled below
                start_line=start + 1,
                end_line=end,
                text="".join(buf),
            )
        )

        if i >= n:
            break
        i = max(start + 1, end - overlap)

    # If we still have leftover lines beyond max_chunks, fold remainder into last chunk trimmed.
    if i < n and chunks:
        leftover = "".join(lines[i:])
        notice = "\n\n/* --- remaining file omitted due to chunk limit --- */\n"
        last = chunks[-1]
        chunks[-1] = CodeChunk(
            index=last.index,
            total=0,
            start_line=last.start_line,
            end_line=len(lines),
            text=last.text + notice + leftover[: max_chunk_tokens * 2],
        )

    total = len(chunks)
    return [
        CodeChunk(
            index=c.index,
            total=total,
            start_line=c.start_line,
            end_line=c.end_line,
            text=c.text,
        )
        for c in chunks
    ]


def chunk_budget_tokens(context_size: int, max_output_tokens: int, prompt_overhead: int) -> int:
    budget = context_size - max_output_tokens - prompt_overhead
    return max(400, budget)
