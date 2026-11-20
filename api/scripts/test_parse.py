from app.llm.parse import parse_review_text

sample = """
{
  "summary": "Division by zero risk.",
  "findings": [
    {
      "severity": "high",
      "title": "ZeroDivisionError",
      "explanation": "b can be 0",
      "line_hint": "return a / b",
      "start_line": 4,
      "end_line": 4
    }
  ],
  "improved_code": "def divide(a, b):\\n    if b == 0:\\n        raise ZeroDivisionError()\\n    return a / b"
}
"""

result = parse_review_text(sample)
assert result["parsed"] is True
assert result["findings"][0].severity.value == "high"
assert result["findings"][0].start_line == 4
print("parse_ok", result["summary"], len(result["findings"]))
