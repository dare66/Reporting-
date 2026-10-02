"""Runtime configuration, from environment variables only."""

from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    api_url: str = Field("http://127.0.0.1:8000/api/v1", alias="AIXBI_API_URL")
    jwt_secret: str = Field("dev-only-change-me-0123456789abcdef0123456789abcdef", alias="JWT_SECRET")
    jwt_issuer: str = Field("aixbi", alias="JWT_ISSUER")
    internal_token: str = Field("dev-internal-token", alias="AI_SERVICE_TOKEN")

    # LLM planning/narrative is optional: without credentials the deterministic
    # semantic planner answers (with real data), and every response says which ran.
    anthropic_api_key: str | None = Field(None, alias="ANTHROPIC_API_KEY")
    llm_enabled: bool = Field(True, alias="AIXBI_LLM_ENABLED")
    model: str = Field("claude-opus-5-5", alias="AIXBI_MODEL")
    price_in_per_mtok: float = Field(4.0, alias="AIXBI_PRICE_IN")
    price_out_per_mtok: float = Field(20.0, alias="AIXBI_PRICE_OUT")

    cors_origins: str = Field("http://localhost:4200,http://127.0.0.1:4200", alias="CORS_ORIGINS")
    request_timeout: float = Field(60.0, alias="AIXBI_API_TIMEOUT")

    @property
    def llm_available(self) -> bool:
        return self.llm_enabled and bool(self.anthropic_api_key)


@lru_cache
def settings() -> Settings:
    return Settings()
