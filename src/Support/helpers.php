<?php

declare(strict_types=1);

namespace {

    use Sahil\Config\Repository;
    use Sahil\Foundation\Application;
    use Sahil\Support\Environment;

    if (!function_exists('app')) {
        /**
         * Resolve the configured application, or a service from its container.
         */
        function app(): Application
        {
            $application = Application::peek();

            if ($application === null) {
                throw new RuntimeException('No Application has been configured for this process yet.');
            }

            return $application;
        }
    }

    if (!function_exists('config')) {
        /**
         * Read a config value from the configured application's repository.
         */
        function config(string $key, mixed $default = null): mixed
        {
            /** @var Repository|null $repository */
            $repository = app()->container()->get('config');

            return $repository->get($key, $default);
        }
    }

    if (!function_exists('env')) {
        /**
         * Read an environment variable from the configured application.
         */
        function env(string $key, bool|int|float|string|null $default = null): mixed
        {
            /** @var Environment|null $environment */
            $environment = app()->container()->get('env');

            return $environment->get($key, $default);
        }
    }

    if (!function_exists('storage_path')) {
        /**
         * Get the path to the storage folder.
         */
        function storage_path(string $path = ''): string
        {
            return app()->basePath('storage'.($path !== '' ? '/'.$path : ''));
        }
    }

    if (!function_exists('base_path')) {
        /**
         * Get the path to the application base folder.
         */
        function base_path(string $path = ''): string
        {
            return app()->basePath($path);
        }
    }

    if (!function_exists('absolute_path')) {
        /**
         * Anchor a configured filesystem path to the application base directory.
         *
         * Configured paths are application-relative: config defaults are built
         * with storage_path(), and the generated .env mirrors that shape (for
         * example `storage/framework/sessions`). Native filesystem calls resolve a
         * relative path against the process working directory, which a web SAPI
         * sets to the document root — silently relocating session files and
         * private uploads into the served tree, where clients could download them.
         * Anchoring here keeps every consumer independent of the working
         * directory. Absolute paths are returned untouched.
         */
        function absolute_path(string $path): string
        {
            if ($path === '') {
                return $path;
            }

            $normalized = str_replace('\\', '/', $path);

            if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
                return $path;
            }

            return base_path($path);
        }
    }

    if (!function_exists('session')) {
        /**
         * Get / set the specified session value.
         *
         * If an array is passed as the key, we will assume you want to set an array of values.
         *
         * @param array<string, mixed>|string|null $key
         * @param mixed $default
         */
        function session(array|string|null $key = null, mixed $default = null): mixed
        {
            /** @var Sahil\Contracts\Session\SessionContract|null $session */
            $session = app()->container()->get(Sahil\Contracts\Session\SessionContract::class);

            if ($key === null) {
                return $session;
            }

            if (is_array($key)) {
                foreach ($key as $k => $v) {
                    $session->put($k, $v);
                }

                return null;
            }

            return $session->get($key, $default);
        }
    }

    if (!function_exists('csrf_token')) {
        /**
         * Issue a fresh CSRF token for this render. Previously issued tokens
         * stay valid inside the session's bounded history ring, so calling
         * this for several forms on one page — or refreshing the page — never
         * invalidates forms still on screen.
         */
        function csrf_token(): string
        {
            /** @var Sahil\Contracts\Session\SessionContract $session */
            $session = app()->container()->get(Sahil\Contracts\Session\SessionContract::class);

            return $session->issueToken();
        }
    }

    if (!function_exists('old')) {
        /**
         * A value the previous request flashed as old input (validation
         * redirect). Sensitive fields — passwords, tokens, secrets — are
         * stripped before flashing, so they can never be read back here.
         */
        function old(string $key, mixed $default = null): mixed
        {
            /** @var Sahil\Contracts\Session\SessionContract $session */
            $session = app()->container()->get(Sahil\Contracts\Session\SessionContract::class);

            $input = $session->getFlash('old', []);

            return is_array($input) ? ($input[$key] ?? $default) : $default;
        }
    }

    if (!function_exists('translator')) {
        /**
         * Resolve the application's translation service.
         *
         * Without a configured application (standalone renderer, early boot
         * failures) a minimal translator over the bundled framework catalog is
         * served instead — framework-owned messages still resolve in the `sw`
         * framework default rather than collapsing to raw keys.
         */
        function translator(): Sahil\I18n\TranslatorContract
        {
            $application = Application::peek();

            if ($application !== null) {
                /** @var Sahil\I18n\TranslatorContract $translator */
                $translator = $application->container()->get(Sahil\I18n\TranslatorContract::class);

                return $translator;
            }

            static $standalone = null;

            return $standalone ??= new Sahil\I18n\Translator(
                new Sahil\I18n\LangLoader([dirname(__DIR__, 2).'/resources/lang']),
                new Sahil\I18n\Locale(new Sahil\Container\Container(), new Repository([])),
            );
        }
    }

    if (!function_exists('__')) {
        /**
         * Translate a message key for the active locale.
         *
         * `__('messages.welcome')`, `__('auth.login_success')` and
         * `__('validation.required', ['attribute' => 'email'])` all work; a
         * missing key falls back to the configured fallback locale and, when it
         * is absent everywhere, returns the key itself.
         *
         * @param array<string, scalar> $replace
         */
        function __(string $key, array $replace = [], ?string $locale = null): string
        {
            return translator()->get($key, $replace, $locale);
        }
    }

    if (!function_exists('trans')) {
        /**
         * Translate a message key — identical to {@see __()}.
         *
         * @param array<string, scalar> $replace
         */
        function trans(string $key, array $replace = [], ?string $locale = null): string
        {
            return translator()->get($key, $replace, $locale);
        }
    }

    if (!function_exists('trans_choice')) {
        /**
         * Translate a message picking its plural form for the active locale's
         * plural rules. `:count` is always replaceable.
         *
         * @param array<string, scalar> $replace
         */
        function trans_choice(string $key, int|float $count, array $replace = [], ?string $locale = null): string
        {
            return translator()->choice($key, $count, $replace, $locale);
        }
    }

}

namespace Sahil {

    use Sahil\Http\Router;

    /**
     * Generate the URL for a named route through the application's router.
     * Unknown names and missing required parameters raise the router's own
     * descriptive exceptions — a wrong URL is never produced silently.
     *
     * `link` is a built-in PHP function (filesystem hardlinks), so a global
     * helper cannot carry this name; the canonical PHP entry point lives in
     * the `Sahil` namespace. Import it once per file to get the intended
     * syntax:
     *
     *     use function Sahil\link;
     *
     *     link('dashboard');                    // '/dashboard'
     *     link('users.show', ['id' => 42]);     // '/users/42'
     *
     * Templates need no import: `{{ link('dashboard') }}` resolves through
     * the render context.
     *
     * @param array<string, mixed> $params
     */
    function link(string $name, array $params = []): string
    {
        /** @var Router $router */
        $router = app()->container()->get(Router::class);

        return $router->generate($name, $params);
    }

}
