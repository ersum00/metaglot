# Contributing

Thanks for considering a contribution. The project is small on purpose — the bar for
new dependencies and new abstractions is high, the bar for tests and documentation
is low.

## Development setup

You need PHP >= 8.2 with `pdo_pgsql`, `curl` and `mbstring`, and Composer.

```sh
git clone https://github.com/ersum00/metaglot.git && cd metaglot
composer install
```

## Running the checks

Everything CI runs, in the same order:

```sh
vendor/bin/php-cs-fixer fix --dry-run --diff   # style (PSR-12); drop --dry-run to fix
vendor/bin/phpstan analyse                     # static analysis, level 6
vendor/bin/phpunit                             # tests
```

The unit tests need no network and no database. The PostgreSQL integration tests are
skipped unless you point them at a throwaway database:

```sh
docker run -d --name metaglot-pg -e POSTGRES_USER=metaglot -e POSTGRES_PASSWORD=metaglot \
  -e POSTGRES_DB=metaglot_test -p 5432:5432 postgres:16

export METAGLOT_TEST_PG_DSN='pgsql:host=127.0.0.1;port=5432;dbname=metaglot_test'
export METAGLOT_TEST_PG_USER=metaglot
export METAGLOT_TEST_PG_PASS=metaglot
vendor/bin/phpunit
```

The integration suite applies the migration and **truncates all tables** before each
test — never point it at a database you care about.

## Ground rules

- **No real API calls in tests.** `Http` is mocked or scripted; the integration tests
  talk only to PostgreSQL.
- **The critical behaviors are regression-tested — keep them green.** The complete-
  snippet rule, the `defaultLanguage` fallback, the title/description limits, quota
  refusal and the partial-success retry semantics all have tests; a PR that changes
  one of these behaviors needs a very good story.
- **No credentials anywhere.** Not in code, not in tests, not in fixtures, not in
  commit messages. Test values are `'dummy'`. CI greps for known token patterns.
- **Be stingy with dependencies.** The standard library plus `ext-pdo`/`ext-curl`
  carried the project this far; a new Composer dependency needs to earn its place.
- **English everywhere** — code, comments, commit messages, documentation.

## Pull requests

- Branch from `main`; keep PRs focused on one change.
- Add or adjust tests for whatever you change.
- Update [CHANGELOG.md](CHANGELOG.md) under **Unreleased**.
- CI (style, phpstan, tests on PHP 8.2/8.3/8.4 with PostgreSQL) must pass.
