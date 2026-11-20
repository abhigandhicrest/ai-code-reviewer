from fastapi import FastAPI

from app.routers import generate, review

app = FastAPI(
    title="AI Code Reviewer API",
    description="Local CodeGemma-backed code review and generation API",
    version="1.0.0",
)

app.include_router(review.router)
app.include_router(generate.router)


@app.get("/")
async def root() -> dict[str, str]:
    return {"service": "ai-code-reviewer-api", "docs": "/docs"}
