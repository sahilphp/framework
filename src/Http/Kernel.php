<?php

declare(strict_types=1);

namespace Sahil\Http;

use Closure;
use Sahil\Container\Container;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Frontend\FrontendRenderer;
use Sahil\Frontend\InvalidFrontendConfigurationException;
use Sahil\Http\Exception\HttpException;
use Sahil\Http\Exception\UnsupportedMediaTypeHttpException;
use Sahil\Http\Middleware\MiddlewareContract;
use Sahil\Http\Middleware\MiddlewareSpec;
use Sahil\Http\Middleware\RouteArgument;
use Sahil\Identification\Identification;
use Sahil\Validation\FormInput;
use Sahil\Validation\ValidationException;
use Throwable;

/**
 * HTTP kernel: middleware pipeline, controller resolution, request scope
 * lifecycle, and exception→Response rendering.
 *
 * Composition:
 *   - the container resolves every middleware and controller handler,
 *   - each {@see handle()} runs inside one request scope (so `scoped()`
 *     bindings resolve; they are dropped when the scope ends, even on
 *     error paths), and
 *   - every exception is converted to a safe response: `HttpException`s
 *     keep their status/headers; everything else becomes 500. Browsers get a
 *     designed HTML error page, machine clients keep the JSON contract (see
 *     {@see ErrorRenderer}); messages appear only in debug mode and
 *     installation paths never leak.
 *
 * The kernel stays reentrant: consecutive `handle()` calls in one process
 * (worker simulation) produce fully isolated request lifecycles.
 */
final class Kernel
{
    /** @var list<MiddlewareContract> global (outermost-first) middleware */
    private array $globalMiddleware = [];

    /**
     * Route middleware aliases (`alias:params` in route definitions).
     * `ability` is the stable built-in alias for Bouncer checks.
     *
     * @var array<string, class-string>
     */
    private array $middlewareAliases = [
        'ability' => \Sahil\Http\Middleware\Authorize::class,
        'auth' => \Sahil\Http\Middleware\Authenticate::class,
        'token' => \Sahil\Http\Middleware\RequireTokenAbility::class,
        'throttle' => \Sahil\Http\Middleware\Throttle::class,
    ];

    /** @var callable(Throwable, Request, ?RouteMatch): ?Response|null */
    private $exceptionHandler = null;

    private bool $debug = false;

    private ?ErrorRenderer $errorRenderer = null;

    /** The route matched for the in-flight request, when one was found. */
    private ?RouteMatch $matchedRoute = null;

    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly ApplicationContract $application,
        private readonly ?FrontendRenderer $frontend = null,
    ) {
    }

    public function useDebugMode(bool $debug): self
    {
        $clone = clone $this;
        $clone->debug = $debug;

        return $clone;
    }

    public function using(MiddlewareContract ...$middleware): self
    {
        $clone = clone $this;
        $clone->globalMiddleware = [...$clone->globalMiddleware, ...array_values($middleware)];

        return $clone;
    }

    /**
     * Install an application-level exception handler.
     *
     * Middleware and handler exceptions unwind through the pipeline (in
     * reverse registration order, running every `finally` and finalize
     * block) and are then handed to this callable with the failing request
     * (a handler that only needs the throwable can ignore it). If it returns
     * a Response, that response is emitted; returning null falls back to the
     * kernel's built-in rendering (see {@see ErrorRenderer}). A third
     * parameter carries the {@see RouteMatch} when the request reached route
     * matching — handlers that do not need it simply omit the parameter.
     *
     * @param callable(Throwable, Request, ?RouteMatch): ?Response $handler
     */
    public function usingExceptionHandler(callable $handler): self
    {
        $clone = clone $this;
        $clone->exceptionHandler = $handler;

        return $clone;
    }

    /**
     * Run one request through the router, middleware and scope lifecycle.
     */
    public function handle(Request $request): Response
    {
        // Drop any request-scoped principal left by a previous request in
        // this worker. `resolved()` — not `has()`/`make()` — so a bound
        // but never-constructed AuthManager is never built just to reset.
        if ($this->container->resolved(\Sahil\Auth\AuthManager::class)) {
            $this->container->make(\Sahil\Auth\AuthManager::class)->resetRequestPrincipal();
        }

        return $this->application->isolatedRequest(function () use ($request): Response {
            $this->matchedRoute = null;

            try {
                // Reject unsupported content types with 415; requests without a
                // content type and JSON/form bodies are allowed. Compare the
                // media type only: real requests carry parameters
                // (`multipart/form-data; boundary=…`, `application/json; charset=utf-8`).
                $contentType = $request->mediaType();
                if ($contentType !== null && !
                    in_array($contentType, ['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'], true)
                ) {
                    // The long-standing machine-readable 415 payload is kept as
                    // is; a browser gets the HTML page instead.
                    if (ErrorRenderer::expectsJson($request)) {
                        $response = Response::json(['error' => 'unsupported_media_type'], 415);
                    } else {
                        throw new UnsupportedMediaTypeHttpException();
                    }
                } else {
                    $response = $this->finalizeFrontend($this->runPipeline($request));
                }
            } catch (HttpException $exception) {
                $response = $this->handleRendered($this->renderError($exception, $request), $exception, $request);
            } catch (Throwable $throwable) {
                // Validation failures are first-class responses — a 303
                // redirect back with flashed errors/old input for
                // browsers, a 422 JSON payload for API clients — not
                // server errors. Everything else renders normally.
                $fallback = $throwable instanceof ValidationException
                    ? $this->respondValidation($throwable, $request)
                    : $this->renderError($throwable, $request);

                $response = $this->handleRendered($fallback, $throwable, $request);
            }

            return $this->identify($response);
        });
    }

    /**
     * Turn a frontend-tagged response into a complete document.
     *
     * Native responses pass through untouched. A frontend response without
     * a configured renderer is a configuration error, not a silently broken
     * page, so it fails with an actionable message.
     */
    private function finalizeFrontend(Response $response): Response
    {
        $target = $response->frontend();

        if ($target === null) {
            return $response;
        }

        if ($this->frontend === null) {
            throw new InvalidFrontendConfigurationException(sprintf(
                'The response targets the [%s] frontend, but the HTTP kernel has no %s. '
                .'Register %s and pass it to the Kernel constructor (the JavaScript starter-kits do this in their provider).',
                $target,
                FrontendRenderer::class,
                FrontendRenderer::class,
            ));
        }

        return $this->frontend->render($response);
    }

    /**
     * Apply the configured framework identification fingerprint — the
     * `X-Powered-By` header and generator metadata in framework-produced
     * HTML documents — as the last step before the response leaves the
     * kernel, so it covers handler, frontend and error responses alike.
     */
    private function identify(Response $response): Response
    {
        /** @var mixed $configuration */
        $configuration = $this->application->config()->get('identification', []);

        return Identification::fromArray(is_array($configuration) ? $configuration : [])
            ->apply($response);
    }

    /**
     * Give the application exception handler (when installed) the chance
     * to replace the built-in rendering; a null return keeps the fallback.
     *
     * A handler that itself throws — a dead log sink, a broken custom
     * renderer — must not escape the request lifecycle while an error is
     * already being reported, so any failure here degrades to the built-in
     * response instead of becoming a second uncaught exception.
     */
    private function handleRendered(Response $fallback, Throwable $throwable, Request $request): Response
    {
        if ($this->exceptionHandler === null) {
            return $fallback;
        }

        try {
            // Handlers declared with two parameters simply ignore the third:
            // it carries the matched route so debug pages can name it.
            return ($this->exceptionHandler)($throwable, $request, $this->matchedRoute) ?? $fallback;
        } catch (Throwable $handlerFailure) {
            // A handler may deliberately rethrow the original throwable (the
            // test kit's withoutExceptionHandling() relies on that); that is
            // honored. Any *other* failure — a dead log sink, a broken custom
            // renderer — degrades to the built-in response instead of
            // escaping the request lifecycle as a second uncaught exception.
            if ($handlerFailure === $throwable) {
                throw $handlerFailure;
            }

            return $fallback;
        }
    }

    /**
     * Turn a {@see ValidationException} into the response the request asks
     * for: `422` JSON for API clients, a `303` redirect back with flashed
     * error bag + sanitized old input for browsers.
     *
     * The redirect target is the exception's explicit override, else the
     * Referer's path (its host is stripped on purpose — a redirect built
     * from request input must never leave the application), else the
     * request path itself.
     */
    private function respondValidation(ValidationException $exception, Request $request): Response
    {
        $errors = $exception->result()->errors();

        if (ErrorRenderer::expectsJson($request)) {
            $first = null;

            foreach ($errors as $messages) {
                $first = $messages[0] ?? null;
                break;
            }

            return Response::json([
                'message' => $first ?? $exception->getMessage(),
                'errors' => $errors,
            ], 422);
        }

        $response = Response::redirect($this->backTarget($exception, $request), 303);

        if (!$this->container->bound(SessionContract::class)) {
            return $response;
        }

        /** @var SessionContract $session */
        $session = $this->container->make(SessionContract::class);
        $session->startFromRequest($request); // no-op when StartSession already ran

        $session->flash('errors', [$exception->bag() ?? 'default' => $errors]);
        $session->flash('old', self::safeOldInput(
            $exception->input() !== [] ? $exception->input() : FormInput::fromRequest($request),
        ));

        // The exception unwound StartSession's save/cookie step, so the
        // flash is persisted and the session cookie attached here.
        $session->save();

        return $session->applyCookieToResponse($response);
    }

    /**
     * Where a failed browser submission goes back to.
     */
    private function backTarget(ValidationException $exception, Request $request): string
    {
        if ($exception->redirectTo() !== null) {
            return $exception->redirectTo();
        }

        $referer = $request->header('Referer');

        if (is_string($referer) && $referer !== '') {
            $parts = parse_url($referer);

            if (is_array($parts) && ($parts['path'] ?? '') !== '') {
                return $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
            }
        }

        $path = $request->path();

        return $path !== '' ? $path : '/';
    }

    /**
     * Old input minus everything that must never be written back to a
     * form (or a session): credentials, tokens and secrets by key name.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private static function safeOldInput(array $input): array
    {
        $safe = [];

        foreach ($input as $key => $value) {
            if (preg_match('/password|token|secret|csrf/i', (string) $key) === 1) {
                continue;
            }

            // Scalars and scalar lists only — a nested structure could
            // carry a sensitive subtree the key name did not reveal.
            if (!is_scalar($value)
                && !(is_array($value) && self::onlyScalars($value))) {
                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }

    /** @param array<array-key, mixed> $values */
    private static function onlyScalars(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_scalar($value) && $value !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render the throwable, with a last-resort answer when even the error
     * pipeline fails: rendering an error must never raise a second one, so
     * the worst possible outcome is the bare JSON contract instead of an
     * exception escaping {@see handle()}.
     */
    private function renderError(Throwable $throwable, Request $request): Response
    {
        try {
            return $this->errorRenderer()->respond($throwable, $request, $this->debug, $this->matchedRoute);
        } catch (Throwable) {
            $status = ExceptionDiagnostics::status($throwable);

            return Response::json(
                $throwable instanceof HttpException
                    ? ['error' => 'http_error', 'status' => $status]
                    : ['error' => 'server_error', 'message' => 'An unexpected server error occurred.'],
                $status,
            );
        }
    }

    /**
     * The renderer is built on first failure only: a booted application that
     * never throws pays no view/cache setup cost.
     */
    private function errorRenderer(): ErrorRenderer
    {
        return $this->errorRenderer ??= ErrorRenderer::fromContainer($this->container);
    }

    /**
     * Global middleware (outermost) → route middleware → handler.
     */
    private function runPipeline(Request $request): Response
    {
        // Global middleware wraps the whole dispatch — including route
        // matching — so request-level concerns (sessions, CSRF, locale
        // selection) apply to error responses for unmatched paths too.
        $dispatch = function () use ($request): Response {
            $match = $this->router->match($request);
            $this->matchedRoute = $match;

            return self::pipelineRunner(
                fn (): Response => $this->dispatchHandler($request, $match),
                $this->routeMiddleware($match),
            )($request);
        };

        return self::pipelineRunner($dispatch, $this->globalMiddleware)($request);
    }

    /**
     * Register a named middleware alias for route definitions:
     *
     *     $kernel->middlewareAlias('ability', Authorize::class);
     *
     *     ->middleware('ability:update-post,{post}')
     *
     * The `ability` alias is pre-registered — it is Bouncer's stable
     * route-level entry point.
     */
    public function middlewareAlias(string $alias, string $class): self
    {
        $alias = trim($alias);

        if ($alias === '' || str_contains($alias, ':')) {
            throw new \InvalidArgumentException(sprintf(
                'HTTP middleware alias [%s] must be a non-empty name without a colon.',
                $alias,
            ));
        }

        $clone = clone $this;
        $clone->middlewareAliases[$alias] = $class;

        return $clone;
    }

    /**
     * Resolve the matched route's middleware specs into instances.
     *
     * @return list<MiddlewareContract> outermost-first
     */
    private function routeMiddleware(RouteMatch $match): array
    {
        /** @var list<MiddlewareContract> $stack outermost-first */
        $stack = [];

        foreach ($match->route()->middlewareList() as $spec) {
            if ($spec instanceof MiddlewareContract) {
                $stack[] = $spec;

                continue;
            }

            // A spec pairs a middleware class with per-route parameters (the
            // kernel passes none to handle()); it resolves inside this
            // request scope, so nothing request-specific is captured earlier.
            if ($spec instanceof MiddlewareSpec) {
                $stack[] = $this->resolveSpec($spec, $match);

                continue;
            }

            // `alias:param,param` resolves a registered middleware alias —
            // `ability:update-post,{post}` is the canonical example.
            if (is_string($spec)
                && ($aliasSpec = $this->resolveAlias($spec, $match)) !== null) {
                $stack[] = $this->resolveSpec($aliasSpec, $match);

                continue;
            }

            $resolved = $this->container->make((string) $spec);

            if (!$resolved instanceof MiddlewareContract) {
                throw new \RuntimeException(
                    sprintf('Configured route middleware [%s] must expose MiddlewareContract::handle(...).', is_object($spec) ? get_class($spec) : (string) $spec),
                );
            }

            $stack[] = $resolved;
        }

        return $stack;
    }

    /**
     * Resolve an `alias:param,param` route middleware string into a spec,
     * or null when the string is not an alias reference.
     */
    private function resolveAlias(string $spec, RouteMatch $match): ?MiddlewareSpec
    {
        if (!str_contains($spec, ':')) {
            return null;
        }

        [$alias, $parameters] = explode(':', $spec, 2);
        $alias = trim($alias);

        if (!isset($this->middlewareAliases[$alias])) {
            return null;
        }

        $values = $parameters === ''
            ? []
            : array_map('trim', explode(',', $parameters));

        return new MiddlewareSpec($this->middlewareAliases[$alias], $values);
    }

    /**
     * Resolve a spec, substituting `{placeholder}` parameters with the
     * matched route's parameters (wrapped in {@see RouteArgument} so the
     * middleware can tell resource arguments from its own settings).
     */
    private function resolveSpec(MiddlewareSpec $spec, RouteMatch $match): MiddlewareContract
    {
        $parameters = [];

        foreach ($spec->parameters() as $parameter) {
            if (is_string($parameter)
                && preg_match('/^\{([A-Za-z_][A-Za-z0-9_-]*)\}$/', $parameter, $found) === 1) {
                $name = $found[1];

                if (!array_key_exists($name, $match->params())) {
                    throw new \RuntimeException(sprintf(
                        'HTTP: route middleware references {%s}, but the matched route has no such parameter.',
                        $name,
                    ));
                }

                $parameters[] = new RouteArgument($name, $match->params()[$name]);

                continue;
            }

            $parameters[] = $parameter;
        }

        return (new MiddlewareSpec($spec->middlewareClass(), $parameters))->resolve($this->container);
    }

    /**
     * @param callable(Request): Response $core
     * @param list<MiddlewareContract> $stack outermost-first
     */
    private static function pipelineRunner(callable $core, array $stack): Closure
    {
        return array_reduce(
            array_reverse($stack),
            static fn (Closure $next, MiddlewareContract $middleware): Closure
                => static fn (Request $request): Response => $middleware->handle($request, $next),
            /** @var callable(Request): Response $core */
            static fn (Request $request): Response => $core($request),
        );
    }

    /**
     * Resolve the handler from the container and call it.
     */
    private function dispatchHandler(Request $request, RouteMatch $match): Response
    {
        $handler = $match->route()->handler();

        $response = $handler instanceof Closure
            ? ($handler)($request, $match->params())
            : $this->container->make($handler[0])->{$handler[1]}($request, $match->params());

        // API resources render through the normal response path.
        if ($response instanceof Resources\Resource || $response instanceof Resources\ResourceCollection) {
            $response = $response->toResponse();
        }

        if (!$response instanceof Response) {
            throw new \RuntimeException(
                sprintf('Handler must return %s; got %s.', Response::class, get_debug_type($response)),
            );
        }

        return $response;
    }
}
