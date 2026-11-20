from __future__ import annotations

import json
import time
from typing import Any, AsyncIterator, Optional

import httpx

from app.config import settings


class LlmUnavailableError(Exception):
    def __init__(self, message: str) -> None:
        self.message = message
        super().__init__(message)


class LlmClient:
    def __init__(self) -> None:
        self.base_url = settings.llm_base_url.rstrip("/")
        self.timeout = settings.llm_timeout_seconds
        # Some llama.cpp builds accept response_format but fail at sampler init
        # ("empty grammar stack"). Remember and stop sending it after first 400.
        self._response_format_unsupported = False

    async def get_model_id(self) -> str:
        try:
            async with httpx.AsyncClient(timeout=30.0) as client:
                response = await client.get(f"{self.base_url}/v1/models")
                response.raise_for_status()
                payload = response.json()
        except httpx.HTTPError as exc:
            raise LlmUnavailableError(
                "Local LLM is unavailable. Start it with scripts/start-codegemma.ps1"
            ) from exc

        data = payload.get("data") or []
        if not data or not data[0].get("id"):
            raise LlmUnavailableError(
                "Local LLM returned no models. Start it with scripts/start-codegemma.ps1"
            )
        return str(data[0]["id"])

    async def health(self) -> dict[str, Optional[str]]:
        try:
            model_id = await self.get_model_id()
            return {"llm": "ok", "model": model_id, "detail": None}
        except LlmUnavailableError as exc:
            return {"llm": "down", "model": None, "detail": exc.message}

    def _chat_body(
        self,
        model_id: str,
        user_content: str,
        stream: bool,
        *,
        temperature: float | None = None,
        max_tokens: int | None = None,
        system: str | None = None,
        response_format: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        messages: list[dict[str, str]] = []
        if system and system.strip():
            messages.append({"role": "system", "content": system.strip()})
        messages.append({"role": "user", "content": user_content})
        body: dict[str, Any] = {
            "model": model_id,
            "messages": messages,
            "temperature": settings.llm_temperature if temperature is None else temperature,
            "max_tokens": settings.llm_max_tokens if max_tokens is None else max_tokens,
            "stream": stream,
        }
        if response_format:
            body["response_format"] = response_format
        return body

    @staticmethod
    def _supports_response_format_retry(status_code: int) -> bool:
        # Older llama.cpp builds reject unknown request fields.
        return status_code in (400, 404, 422)

    async def chat(
        self,
        user_content: str,
        *,
        temperature: float | None = None,
        max_tokens: int | None = None,
        system: str | None = None,
        response_format: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        model_id = await self.get_model_id()
        rf = response_format if not self._response_format_unsupported else None
        bodies = [
            self._chat_body(
                model_id,
                user_content,
                stream=False,
                temperature=temperature,
                max_tokens=max_tokens,
                system=system,
                response_format=rf,
            )
        ]
        if rf:
            bodies.append(
                self._chat_body(
                    model_id,
                    user_content,
                    stream=False,
                    temperature=temperature,
                    max_tokens=max_tokens,
                    system=system,
                )
            )

        started = time.perf_counter()
        try:
            async with httpx.AsyncClient(timeout=self.timeout) as client:
                response = None
                for index, body in enumerate(bodies):
                    response = await client.post(
                        f"{self.base_url}/v1/chat/completions",
                        json=body,
                    )
                    if response.status_code < 400:
                        break
                    if index == 0 and rf and self._supports_response_format_retry(response.status_code):
                        if b"grammar" in response.content.lower():
                            self._response_format_unsupported = True
                        continue
                    response.raise_for_status()
                assert response is not None
                payload = response.json()
        except httpx.HTTPError as exc:
            raise LlmUnavailableError(
                "Local LLM request failed. Ensure CodeGemma is running on "
                f"{self.base_url} (scripts/start-codegemma.ps1)."
            ) from exc

        latency_ms = int((time.perf_counter() - started) * 1000)
        choices = payload.get("choices") or []
        if not choices:
            raise LlmUnavailableError("Local LLM returned an empty response.")

        message = choices[0].get("message") or {}
        content = (message.get("content") or "").strip()
        if not content:
            raise LlmUnavailableError("Local LLM returned empty review text.")

        return {
            "review": content,
            "model": model_id,
            "latency_ms": latency_ms,
        }

    async def chat_stream(
        self,
        user_content: str,
        *,
        temperature: float | None = None,
        max_tokens: int | None = None,
        system: str | None = None,
        response_format: dict[str, Any] | None = None,
    ) -> AsyncIterator[dict[str, Any]]:
        """Yield {type: token|error|done, ...} events while calling llama.cpp stream API."""
        try:
            model_id = await self.get_model_id()
        except LlmUnavailableError as exc:
            yield {"type": "error", "detail": exc.message}
            return

        kwargs: dict[str, Any] = {
            "temperature": temperature,
            "max_tokens": max_tokens,
            "system": system,
        }
        rf = response_format if not self._response_format_unsupported else None
        bodies = [
            self._chat_body(model_id, user_content, stream=True, response_format=rf, **kwargs)
        ]
        if rf:
            bodies.append(self._chat_body(model_id, user_content, stream=True, **kwargs))

        started = time.perf_counter()
        chunks: list[str] = []

        for index, body in enumerate(bodies):
            try:
                async with httpx.AsyncClient(timeout=self.timeout) as client:
                    async with client.stream(
                        "POST",
                        f"{self.base_url}/v1/chat/completions",
                        json=body,
                        headers={"Accept": "text/event-stream"},
                    ) as response:
                        if response.status_code >= 400:
                            detail = (await response.aread()).decode("utf-8", errors="replace")
                            if (
                                index == 0
                                and rf
                                and self._supports_response_format_retry(response.status_code)
                            ):
                                if "grammar" in detail.lower():
                                    self._response_format_unsupported = True
                                continue
                            yield {
                                "type": "error",
                                "detail": _friendly_llm_error(response.status_code, detail),
                            }
                            return

                        async for line in response.aiter_lines():
                            if not line:
                                continue
                            if line.startswith(":"):
                                continue
                            if not line.startswith("data:"):
                                continue

                            data = line[5:].strip()
                            if data == "[DONE]":
                                break

                            try:
                                payload = json.loads(data)
                            except json.JSONDecodeError:
                                continue

                            choices = payload.get("choices") or []
                            if not choices:
                                continue
                            delta = choices[0].get("delta") or {}
                            token = delta.get("content") or ""
                            if not token:
                                # some servers put full message content on non-delta frames
                                message = choices[0].get("message") or {}
                                token = message.get("content") or ""
                            if token:
                                chunks.append(token)
                                yield {"type": "token", "content": token}
            except httpx.HTTPError as exc:
                if index == 0 and rf:
                    chunks.clear()
                    continue
                yield {
                    "type": "error",
                    "detail": (
                        "Local LLM stream request failed. Ensure CodeGemma is running on "
                        f"{self.base_url} (scripts/start-codegemma.ps1). ({exc})"
                    ),
                }
                return
            break

        text = "".join(chunks).strip()
        if not text:
            yield {"type": "error", "detail": "Local LLM returned empty review text."}
            return

        yield {
            "type": "done",
            "review": text,
            "model": model_id,
            "latency_ms": int((time.perf_counter() - started) * 1000),
        }


def _friendly_llm_error(status_code: int, detail: str) -> str:
    lowered = detail.lower()
    if "exceed_context_size" in lowered or "exceeds the available context size" in lowered:
        return (
            "This file is too large for the local model context. "
            "Restart CodeGemma with a larger context (scripts/start-codegemma.ps1 uses -c 8192), "
            "or upload a smaller file / review a shorter snippet. "
            f"Raw error: {detail[:240]}"
        )
    return f"Local LLM stream failed ({status_code}): {detail[:300]}"


llm_client = LlmClient()
