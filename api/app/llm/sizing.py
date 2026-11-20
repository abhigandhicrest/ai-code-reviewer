from __future__ import annotations


def estimate_tokens(text: str) -> int:
    # Conservative char-based estimate for Gemma/CodeGemma-ish tokenizers.
    return max(1, (len(text) + 3) // 4)


def fit_code_to_context(
    code: str,
    *,
    prompt_overhead_tokens: int,
    context_size: int,
    max_output_tokens: int,
) -> tuple[str, bool, str | None]:
    """
    Truncate oversized source so prompt + completion fit in llama.cpp context.

    Returns (code, was_truncated, notice).
    """
    budget = context_size - max_output_tokens - prompt_overhead_tokens
    if budget < 256:
        budget = max(256, context_size // 4)

    code_tokens = estimate_tokens(code)
    if code_tokens <= budget:
        return code, False, None

    # Keep head and tail so imports/start and ending logic remain visible.
    target_chars = budget * 4
    head = int(target_chars * 0.7)
    tail = max(0, target_chars - head)
    truncated = (
        code[:head]
        + "\n\n/* --- truncated for model context limit --- */\n\n"
        + (code[-tail:] if tail else "")
    )
    notice = (
        f"Input was truncated to fit the local model context "
        f"(~{code_tokens} tokens estimated, budget ~{budget} of {context_size}). "
        "Review may miss issues in the omitted middle section."
    )
    return truncated, True, notice
