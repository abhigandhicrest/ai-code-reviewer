from enum import Enum
from typing import Dict, List, Literal, Optional

from pydantic import BaseModel, Field, model_validator


class FocusArea(str, Enum):
    bugs = "bugs"
    security = "security"
    style = "style"
    improve = "improve"


class Severity(str, Enum):
    high = "high"
    medium = "medium"
    low = "low"


class ReviewFile(BaseModel):
    path: str = Field(..., min_length=1)
    language: str = Field(default="other", min_length=1)
    code: str = Field(..., min_length=1)


class ReviewRequest(BaseModel):
    code: Optional[str] = None
    language: str = Field(default="python", min_length=1)
    focus: Optional[List[FocusArea]] = None
    files: Optional[List[ReviewFile]] = None
    profile: Optional[str] = None
    profile_instructions: Optional[str] = None
    generate_code: bool = True
    review_mode: Literal["full", "diff"] = "full"
    standards_by_language: Optional[Dict[str, str]] = None

    @model_validator(mode="after")
    def require_code_or_files(self) -> "ReviewRequest":
        has_code = bool(self.code and self.code.strip())
        has_files = bool(self.files)
        if not has_code and not has_files:
            raise ValueError("Provide code or files")
        return self


class Finding(BaseModel):
    severity: Severity = Severity.medium
    title: str
    explanation: str
    line_hint: Optional[str] = None
    file_path: Optional[str] = None
    start_line: Optional[int] = None
    end_line: Optional[int] = None


class ReviewResponse(BaseModel):
    review: str
    summary: str = ""
    findings: List[Finding] = Field(default_factory=list)
    improved_code: Optional[str] = None
    model: str
    latency_ms: int
    parsed: bool = False
    chunks_count: int = 1
    files_count: int = 1
    file_results: List[dict] = Field(default_factory=list)


class HealthResponse(BaseModel):
    status: str
    llm: str
    model: Optional[str] = None
    detail: Optional[str] = None


class GenerateRequest(BaseModel):
    instruction: str = Field(..., min_length=1)
    language: str = Field(default="python", min_length=1)
    context: Optional[str] = None
    profile_instructions: Optional[str] = None
    source_code: Optional[str] = Field(default=None, max_length=200_000)


class GenerateResponse(BaseModel):
    notes: str = ""
    code: str
    model: str
    latency_ms: int
    parsed: bool = False
    language: str = "python"
