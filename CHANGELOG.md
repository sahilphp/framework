# Changelog

All notable changes to `sahilphp/framework` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `Sahil\Contracts\View\ComponentRendererContract`: templates can render a
  server-driven UI component by name, and a UI package (Firefiber) supplies
  the implementation. `ViewEngine` accepts the renderer as an optional fourth
  constructor argument.
- `@component('Name')` and `<fiber:component name="Name" />` template syntaxes.
  Both compile to one `$__ctx->component()` call that emits rendered markup,
  support fully-qualified class names, kebab-case attributes mapped to
  camelCase properties, and `:attr="expression"` bound attributes.
- Framework-owned `Sahil\Console` application, built-in project commands,
  native `unda:*` generators, and the project installer used by the
  `sahilphp/sahilphp` starter.
- `unda:job` generator for `Sahil\Queue\Jobs\Job` subclasses.
- Asset-manager `kit` JavaScript entry so generated applications can load
  `resources/js/app.js` as `assets/kit.js`.

### Fixed

- Component templates now render through the bound component renderer instead
  of emitting a bare, discarded `$__ctx->component(...)` call and leaving
  marker comments in the response.
- `RenderContext::component()` validates names and fails with a clear
  `ViewRenderException` when no renderer is installed or the name is invalid.
- Compiled-template cache version bumped so existing compiled views are
  regenerated with the component support.
- Generator command help no longer prints every option twice.
- Nested policies (`unda:policy Admin/UserPolicy`) document the matching
  nested model (`App\Models\Admin\User`).
- The job example and generated job implement `handle(): mixed`, matching
  `JobContract`.

### Changed

- `sahilphp/sahilphp`, `sahilphp/installer`, `sahilphp/react`,
  `sahilphp/vue`, and `sahilphp/svelte` require framework `^1.1`. Publish this
  framework release before those dependent packages are installed.
- The starter application lives in the canonical `sahilphp/` project
  directory; no separate `starter-kit/` project is supported.

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
