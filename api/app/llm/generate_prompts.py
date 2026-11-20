from __future__ import annotations

# Sent as OpenAI-style response_format; llama.cpp converts it to a grammar so the
# model can only emit this shape. Older servers that reject the field are retried
# without it (see LlmClient).
GENERATE_JSON_SCHEMA: dict = {
    "type": "json_schema",
    "json_schema": {
        "name": "code_generation",
        "strict": True,
        "schema": {
            "type": "object",
            "properties": {
                "notes": {"type": "string"},
                "code": {"type": "string"},
            },
            "required": ["notes", "code"],
            "additionalProperties": False,
        },
    },
}


def build_repair_prompt(code: str, language: str, error: str) -> str:
    return (
        f"You are an expert {language} software engineer.\n"
        "The code below failed an automated syntax check. Return ONLY one JSON object "
        "(no markdown fences) with this shape:\n"
        '{\n  "notes": "what you fixed",\n  "code": "the corrected, complete, runnable source — no fences inside the string"\n}\n'
        "Rules:\n"
        "- Fix the syntax errors; keep the intended behavior and structure.\n"
        "- Do not add features or refactor beyond what is needed to make it valid.\n"
        "- Output plain source only inside the code string.\n\n"
        f"Checker output:\n{error}\n\n"
        f"Language: {language}\n"
        f"Code:\n```{language}\n{code}\n```\n"
    )


def build_modify_prompt(
    instruction: str,
    language: str,
    source_code: str,
    *,
    context: str | None = None,
    profile_instructions: str | None = None,
) -> str:
    profile_note = (
        f"Extra guidance: {profile_instructions.strip()}\n"
        if profile_instructions and profile_instructions.strip()
        else ""
    )
    context_block = ""
    if context and context.strip():
        context_block = (
            "Additional context / constraints to consider:\n"
            f"```{language}\n{context.strip()}\n```\n\n"
        )

    return (
        f"You are an expert {language} software engineer.\n"
        "Modify the provided source code according to the requested change. "
        "Return the COMPLETE modified file — do not return only a diff or snippet.\n"
        f"{profile_note}"
        "Respond with ONLY one JSON object (no markdown fences around the JSON) using this shape:\n"
        "{\n"
        '  "notes": "brief explanation of what you changed",\n'
        '  "code": "the full modified source code only — no markdown fences inside this string"\n'
        "}\n"
        "Rules:\n"
        "- Apply the requested change precisely; keep unrelated code intact.\n"
        "- Preserve the file's structure, naming, and style unless the change requires otherwise.\n"
        "- Keep the code runnable; do not invent secrets — use placeholders if needed.\n\n"
        f"Language: {language}\n"
        f"Requested change:\n{instruction.strip()}\n\n"
        f"Source code to modify:\n```{language}\n{source_code.strip()}\n```\n\n"
        f"{context_block}"
    )


def build_generate_prompt(
    instruction: str,
    language: str,
    *,
    context: str | None = None,
    profile_instructions: str | None = None,
) -> str:
    profile_note = (
        f"Extra guidance: {profile_instructions.strip()}\n"
        if profile_instructions and profile_instructions.strip()
        else ""
    )
    context_block = ""
    if context and context.strip():
        context_block = (
            "Optional context / existing code to consider:\n"
            f"```{language}\n{context.strip()}\n```\n\n"
        )

    return (
        f"You are an expert {language} software engineer.\n"
        "Generate complete, runnable source code that satisfies the instruction.\n"
        f"{profile_note}"
        "Respond with ONLY one JSON object (no markdown fences around the JSON) using this shape:\n"
        "{\n"
        '  "notes": "brief explanation of what you generated",\n'
        '  "code": "full source code only — no markdown fences inside this string"\n'
        "}\n"
        "Rules:\n"
        "- Prefer a single file or short module unless the instruction requires more.\n"
        "- Match the requested language idioms and common best practices.\n"
        "- Do not invent secrets; use placeholders if credentials are needed.\n"
        "- If context is provided, integrate with it rather than ignoring it.\n\n"
        f"Language: {language}\n"
        f"Instruction:\n{instruction.strip()}\n\n"
        f"{context_block}"
    )
