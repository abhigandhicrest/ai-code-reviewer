from typing import List, Optional

from app.llm.checklists import checklist_for_language
from app.schemas import FocusArea


def build_review_prompt(
    code: str,
    language: str,
    focus: Optional[List[FocusArea]] = None,
    *,
    chunk_index: int | None = None,
    chunk_total: int | None = None,
    start_line: int | None = None,
    end_line: int | None = None,
    file_path: str | None = None,
    profile_instructions: str | None = None,
    generate_code: bool = True,
    review_mode: str = "full",
    coding_standards: str | None = None,
) -> str:
    if focus:
        focus_list = ", ".join(item.value for item in focus)
    else:
        focus_list = "bugs, security, style, improve"

    chunk_note = ""
    if chunk_index and chunk_total and chunk_total > 1:
        chunk_note = (
            f"This is chunk {chunk_index}/{chunk_total} "
            f"(file lines {start_line}-{end_line} shown as this snippet). "
            "Review only this chunk. start_line/end_line are 1-based inside THIS snippet "
            "(the first line of the code block is 1).\n"
        )

    file_note = f"File path: {file_path}\n" if file_path else ""
    profile_note = (
        f"Profile instructions: {profile_instructions.strip()}\n"
        if profile_instructions and profile_instructions.strip()
        else ""
    )
    checklist = checklist_for_language(language)
    standards_note = (
        f"Coding standards ({language}):\n{coding_standards.strip()}\n"
        if coding_standards and coding_standards.strip()
        else ""
    )

    diff_mode = (review_mode or "full").lower() == "diff"

    if generate_code:
        if diff_mode:
            improved_field = (
                '  "improved_code": "corrected hunk or fixed snippet for the changed code '
                '(plain source; may be a partial file)"\n'
            )
            generate_rules = (
                "- Prefer improved_code as a corrected version of the changed region.\n"
                "- improved_code must be plain source only (no markdown fences inside the string).\n"
            )
        else:
            improved_field = (
                '  "improved_code": "complete improved/fixed source for this file or chunk '
                '(valid runnable code, not a diff; apply your findings)"\n'
            )
            generate_rules = (
                "- ALWAYS fill improved_code with a full corrected version of the provided code "
                "when there is at least one finding.\n"
                "- If findings is empty, set improved_code to the original code unchanged "
                "or a lightly cleaned version.\n"
                "- improved_code must be plain source only (no markdown fences inside the string).\n"
            )
    else:
        improved_field = '  "improved_code": ""\n'
        generate_rules = "- Set improved_code to an empty string (code generation disabled).\n"

    diff_note = ""
    if diff_mode:
        diff_note = (
            "This input is a unified git diff hunk (lines may start with space, +, or -). "
            "Review the change: prioritize issues introduced on '+' lines; "
            "do not require a full-file rewrite unless necessary.\n"
        )

    return (
        f"You are a careful code reviewer and code generator. Review the following {language} code.\n"
        f"Focus: {focus_list}.\n"
        f"{file_note}"
        f"{profile_note}"
        f"{diff_note}"
        f"{checklist}\n"
        f"{standards_note}"
        f"{chunk_note}"
        "Respond with ONLY one JSON object (no markdown fences, no extra text) using this shape:\n"
        "{\n"
        '  "summary": "one short paragraph",\n'
        '  "findings": [\n'
        "    {\n"
        '      "severity": "high|medium|low",\n'
        '      "title": "short issue title",\n'
        '      "explanation": "what is wrong and why",\n'
        '      "start_line": 12,\n'
        '      "end_line": 14,\n'
        '      "line_hint": "optional extra location hint"\n'
        "    }\n"
        "  ],\n"
        f"{improved_field}"
        "}\n"
        "Rules:\n"
        "- Include at least one finding when issues exist.\n"
        "- Use empty findings array only if the code looks fine.\n"
        "- Prefer concrete, actionable findings.\n"
        "- Always set start_line (1-based in the provided snippet). Set end_line when the issue spans lines.\n"
        "- high = security/bugs/data loss; medium = should-fix; low = style/standards.\n"
        "- Report coding-standard violations as findings with title prefixed by [standard] "
        "and severity low or medium.\n"
        f"{generate_rules}\n"
        f"```{language}\n"
        f"{code}\n"
        "```"
    )
