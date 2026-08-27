# metaglot

Localizes video titles and descriptions into multiple languages so they can be **found by search** — SEO localization, not literal translation. Uses the YouTube Data API v3 to read and write metadata, and any OpenAI-compatible LLM endpoint (a local [Ollama](https://ollama.com) by default) to produce titles a native speaker of each target language would actually search for.

> Early development. The CLI works; full documentation is on its way.

## Requirements

- PHP >= 8.2 with `pdo_pgsql`, `curl` and `mbstring`
- PostgreSQL
- An OpenAI-compatible chat completions endpoint (e.g. Ollama)
- A Google Cloud project with the YouTube Data API v3 enabled and an OAuth client

## Install

```sh
git clone https://github.com/ersum00/metaglot.git
cd metaglot
composer install
psql -d <database> -f migrations/001_initial.sql
cp .env.example .env   # then fill in the values
```

## Usage

```sh
php bin/metaglot --channel=1 --dry-run   # preview without writing anything
php bin/metaglot --channel=1             # process one channel
php bin/metaglot --all                   # process every active channel (e.g. hourly from cron)
```

## License

MIT
