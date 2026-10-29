# Contributing to SahilPHP

Thanks for taking the time to contribute. This document covers the framework
package; the starter kit has its own copy.

## Requirements

- PHP **8.1 or newer** (CI runs 8.1, 8.2, 8.3 and 8.4)
- Composer 2.x
- `ext-openssl`, `ext-json`, and `ext-pdo` with a MySQL or PostgreSQL driver
  for the database integration tests

## Getting started

```bash
git clone https://github.com/sahilphp/framework.git
cd framework
composer install
composer test
```

## Quality gates

Every change must keep these green; CI runs the same commands:

```bash
composer lint          # php -l over src/ and tests/
composer test          # PHPUnit
composer analyse       # PHPStan level 6 over src/
composer check-style   # PHP-CS-Fixer, PSR-12, dry run
composer check         # lint + test + analyse
```

Apply formatting with `composer fix-style`.

Database integration tests are skipped unless configured:

```bash
SAHIL_DB_TEST_MYSQL="mysql://user:pass@127.0.0.1:3306/sahil_test" \
SAHIL_DB_TEST_PGSQL="pgsql://user:pass@127.0.0.1:5432/sahil_test" \
composer test
```

## Pull requests

- One focused change per pull request; describe the behaviour it changes and
  how you verified it.
- Add or update tests. Bug fixes need a test that fails before the fix.
- Update `CHANGELOG.md` under `## [Unreleased]`.
- Keep the public API documented with docblocks; `src/` is analysed at
  PHPStan level 6.
- Do not commit `.env` files, credentials or generated caches.

## Coding standards

- PSR-12 (enforced by PHP-CS-Fixer), `declare(strict_types=1);` in every file,
  one class per file, PSR-4 layout under `Sahil\`.
- Prefer explicit types and `readonly` value objects; avoid `mixed` in new
  public APIs where a concrete type is possible.
- Security-sensitive code (crypto, sessions, SQL, file access) needs a test
  and a comment explaining the threat it defends against.

## Reporting bugs and vulnerabilities

Bugs: open a GitHub issue with a minimal reproduction.
Security issues: **do not** open a public issue — follow `SECURITY.md`.

## License

By contributing you agree that your contribution is licensed under the MIT
License (see `LICENSE`).
