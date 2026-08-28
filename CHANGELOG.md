# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- CLI (`bin/metaglot`) that localizes video titles and descriptions into multiple
  languages via the YouTube Data API v3, driven by any OpenAI-compatible LLM
  endpoint (local Ollama by default).
- Search-aware localization prompt: titles phrased the way native speakers search,
  with proper nouns, numbers, hashtags and description structure preserved.
- PostgreSQL-backed state: channels, per-video progress and quota accounting
  (`migrations/001_initial.sql`).
- Idempotent runs: unchanged videos with all target languages present are skipped
  without any API call; partial LLM results are written and stay retryable.
- Quota protection: per-operation cost table, warning at 90% of the daily limit,
  hard refusal before any call that would exceed 100%.
- HTTP retry with exponential backoff (1s/4s/16s) for 429 and 5xx responses.
- Complete-snippet writes on `videos.update` so YouTube does not delete `tags` or
  `categoryId`, and automatic `defaultLanguage` fallback so localizations are not
  silently ignored.
- Clear `invalid_grant` diagnostics explaining the 7-day refresh-token expiry of
  consent screens left in "Testing" mode.
- `channel:delete` command that removes a channel with its stored OAuth
  credentials, videos and quota history.
- Test suite (unit + PostgreSQL integration) and CI on PHP 8.2/8.3/8.4 with
  php-cs-fixer, PHPStan (level 6) and a PostgreSQL service container.
- Documentation: README, Google Cloud setup guide (`docs/SETUP.md`), prompt tuning
  guide (`docs/PROMPTING.md`) and this changelog.
- Docker deployment: `Dockerfile` (php:8.3-cli-alpine, non-root, production
  dependencies only), `docker-compose.yml` with a one-shot `app` service and a
  private `postgres:16-alpine` service, and `docs/DEPLOY.md`.
