# Changelog

All notable changes to `sahilphp/framework` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Framework technology identification (`Sahil\Identification`): the HTTP
  kernel applies the configured `identification` namespace to every
  response — `X-Powered-By: SahilPHP` on all responses and
  `<meta name="generator" content="SahilPHP[ x.y.z]">` inside documents
  produced by `Response::html()` (rendered views, frontend documents,
  error pages). The installed `sahilphp/framework` version is resolved
  from Composer metadata; `identification.enabled`,
  `identification.expose_version`, `identification.powered_by` and
  `identification.version` control the fingerprint. A document's own
  `generator` declaration always wins (a bare `SahilPHP` signature is
  completed with the installed version), and arbitrary `text/html`
  payloads are never modified.
- `Response::isHtmlDocument()` marks responses produced by the framework's
  HTML document pipeline; `Response::withBody()` replaces a body while
  preserving status, headers and document marking.
- `fingerprint/wappalyzer/` ships a contribution-ready WebAppAnalyzer
  (Wappalyzer) technology definition, the official icon and the vendored
  upstream schema the test suite validates against.
- `RenderManager::alias()` registers an extra environment name for an
  existing adapter so applications can alias their own environments.
- `Link::firefiber()` mounts a page class implementing
  `Sahil\Contracts\Routing\MountablePageContract` (provided by Firefiber's
  `Page`) on a GET route without a controller —
  `Link::firefiber(HomePage::class)` uses the page's own `path()` and
  `Link::firefiber('/posts/{id}', ShowPost::class)` mounts at an explicit
  path template whose parameters reach the page's `show()` handler.
- `Application::pin()` pins an existing application as the
  process-configured handle so `app()` keeps resolving it after a
  `free()`/bootstrap-cache cycle.
- Designed HTML error pages: `Sahil\Http\ErrorRenderer` answers browser
  requests with a self-contained page for the status (`400`, `401`, `403`,
  `404`, `405`, `419`, `429`, `500`, `503` and generic `4xx`/`5xx` groups),
  while JSON clients keep the existing error contract. A template failure
  degrades to a built-in page instead of a blank or JSON body.
- `php sahil error:publish` copies the framework error pages into
  `resources/views/errors/` so an application can customize them (published
  views win over the framework's copies). Nothing is added to a project
  until the command runs.
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

### Changed

- `Kernel::usingExceptionHandler()` handlers now receive the failing request
  as a second argument and the matched route as an optional third
  (`callable(Throwable, Request, ?RouteMatch): ?Response`); handlers that
  only need the throwable keep working unchanged.
- Debug error responses now carry the full request picture: the exception
  page (template and built-in fallback) shows the request line, matched
  route, `X-Request-Id` and a UTC timestamp, wraps the stack trace in a
  collapsible block and offers a copy-details button; the JSON debug payload
  gains `exception` and `request_id` keys.
- `Environment::get()` accepts the full boolean spellings — `1`/`0`,
  `yes`/`no`, `on`/`off`, `true`/`false` — when read with a boolean default
  (`env('APP_DEBUG', false)`); unrecognized values stay strings so
  configuration validation can reject them. `local` is a recognized
  `app.environment`.
- Unsupported media types still answer a machine-readable
  `{"error":"unsupported_media_type"}` 415, while a browser gets the 415
  error page.

### Removed

- The `fire`/`fiber` controller-render helpers (`Render::fire()`,
  `Render::fiber()`, `Response::fire()`, `Response::fiber()` and the
  `$this->fire()`/`$this->fiber()` controller helpers) never shipped and are
  gone: Firefiber pages mount on the route table via `Link::firefiber()`
  instead of being rendered from controllers.

### Fixed

- Component templates now render through the bound component renderer instead
  of emitting a bare, discarded `$__ctx->component(...)` call and leaving
  marker comments in the response.
- `RenderContext::component()` validates names and fails with a clear
  `ViewRenderException` when no renderer is installed or the name is invalid.
- `Render::manager()` no longer autowires an empty `RenderManager` when the
  class is unbound: `Container::has()` is true for any concrete class, so the
  check now uses `bound()` and unbound applications correctly fall back to the
  native `html` adapter instead of failing every render.
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
