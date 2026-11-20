from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    llm_base_url: str = "http://127.0.0.1:8080"
    llm_timeout_seconds: float = 600.0
    llm_max_tokens: int = 1024
    llm_temperature: float = 0.2
    # Instruction-based code generation (same model, different knobs).
    llm_generate_max_tokens: int = 4096
    llm_generate_temperature: float = 0.4
    # Must match llama serve -c (CodeGemma trains at 8192).
    llm_context_size: int = 8192
    # Tokens reserved for instructions around the source code.
    llm_prompt_overhead_tokens: int = 450


settings = Settings()
