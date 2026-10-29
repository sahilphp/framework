# Changelog

All notable changes to `sahilphp/framework` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-29

First public release.

### Added

- App-key support: `Sahil\Encryption\Encrypter` (AES-256-GCM + HMAC-SHA-256)
  and `Sahil\Encryption\KeyGenerator`, both driven by `APP_KEY`.
- `Sahil\Http\Middleware\SecurityHeaders`: `nosniff`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy` and `Cross-Origin-Opener-Policy`
  on every response, with opt-in CSP/overrides.
- Optional queue payload allow-list (`Worker::$allowedJobClasses`) to make
  object injection impossible even when the jobs table is tampered with.
- `phpstan.neon` (level 6) and `.php-cs-fixer.dist.php` (PSR-12) so the
  documented quality gates run.
- `.gitattributes` trimming tests, CI files and dev tooling from the
  distributed archive.
- PHPUnit coverage for encryption, security headers and the request logger.

### Changed

- **Minimum PHP version is 8.1** (was 8.4). `symfony/mailer` and
  `symfony/mime` accept `^6.4 || ^7.1`; PHPUnit moved to `^10.5`.
- `Sahil\Http\Middleware\Throttle` takes the store explicitly
  (`new Throttle($key, $store)`) instead of falling back to a container
  attribute on the request that the framework never set.
- `Sahil\Support\Logging\RequestLogger::record()` is now
  `record(string $message, int $status = 200, array $context = [])`, so the
  required status no longer follows an optional parameter.
- `Paginator::forRequest()` honours the `perPage` query parameter (it was
  computed and then discarded).
- SMTP `tls` mode requires STARTTLS on `symfony/mailer >= 7.1` and uses
  opportunistic STARTTLS on the 6.4 LTS line, which exposes no switch.
- README documents the library/starter-kit split, the directory layout and
  which commands belong to which repository.

### Removed

- Dead `src/Console` subsystem and the application entry point; both belong
  to the starter kit.

### Fixed

- Integration-test data providers no longer error when the database
  environment variables are unset (they skip instead).
- `FileCache`/`RedisCache` return `int|false` from `ttL()`, matching what
  they actually return.
