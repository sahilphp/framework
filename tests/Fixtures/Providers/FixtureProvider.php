<?php

declare(strict_types=1);

namespace Sahil\Tests\Fixtures\Providers;

use RuntimeException;
use Sahil\Assets\AssetManager;
use Sahil\Container\Container;
use Sahil\Contracts\Assets\AssetManagerContract;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Contracts\Mail\MailerContract;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Contracts\Session\SessionDriverContract;
use Sahil\Contracts\View\ViewContract;
use Sahil\Foundation\ServiceProvider;
use Sahil\Http\Kernel;
use Sahil\Http\Middleware\RequestIdMiddleware;
use Sahil\Http\Middleware\StartSession;
use Sahil\Http\Middleware\VerifyCsrfToken;
use Sahil\Http\Route;
use Sahil\Http\Router;
use Sahil\Mail\MailManager;
use Sahil\Session\FileSessionDriver;
use Sahil\Session\Session;
use Sahil\Support\Logging\LoggerContract;
use Sahil\Testing\TestLogger;
use Sahil\View\TemplateCompiler;
use Sahil\View\TemplateResolver;
use Sahil\View\ViewEngine;

/**
 * Boots the framework test fixture with the smallest useful application graph.
 *
 * This is deliberately test infrastructure, not a starter-kit replacement:
 * it wires only the services exercised by framework-owned application tests.
 * A real installation owns its providers and entry points.
 */
final class FixtureProvider extends ServiceProvider
{
    public function register(): void
    {
        $application = $this->app();
        $container = $application->container();
        $routesFile = $application->basePath('routes/web.php');

        $container->singleton(LoggerContract::class, static fn (): LoggerContract => new TestLogger());

        $container->singleton(SessionDriverContract::class, static function (Container $container): SessionDriverContract {
            $application = $container->make(ApplicationContract::class);
            $path = (string) $application->config()->get(
                'session.path',
                $application->basePath('storage/framework/sessions'),
            );

            return new FileSessionDriver($path);
        });

        $container->singleton(SessionContract::class, static function (Container $container): SessionContract {
            $application = $container->make(ApplicationContract::class);
            $config = $application->config();

            $session = new Session(
                $container->make(SessionDriverContract::class),
                (int) $config->get('session.lifetime', 120) * 60,
                (bool) $config->get('session.secure', false),
                (bool) $config->get('session.http_only', true),
                (string) $config->get('session.same_site', 'lax'),
            );

            $session->setName((string) $config->get('session.cookie', 'sahil_session'));

            return $session;
        });

        $container->singleton(StartSession::class, static fn (Container $container): StartSession
            => new StartSession($container->make(SessionContract::class)));

        $container->singleton(VerifyCsrfToken::class, static function (Container $container): VerifyCsrfToken {
            $application = $container->make(ApplicationContract::class);

            return new VerifyCsrfToken(
                $container->make(SessionContract::class),
                (array) $application->config()->get('session.csrf_except', []),
            );
        });

        $container->singleton(AssetManager::class, static function (Container $container): AssetManager {
            $application = $container->make(ApplicationContract::class);
            $config = $application->config();

            return new AssetManager(
                basePath: $application->basePath(),
                profile: (string) $config->get('assets.profile', 'tailwind'),
                mode: (string) $config->get('assets.mode', 'development'),
                environment: (string) $config->get('app.environment', 'testing'),
                devServer: (string) $config->get('assets.dev_server', 'http://127.0.0.1:5173'),
                baseUrl: (string) $config->get('assets.base_url', ''),
                buildPath: (string) $config->get('assets.build_path', 'public/build'),
                buildPublicPath: (string) $config->get('assets.build_public_path', 'build'),
                verify: (bool) $config->get('assets.verify', true),
            );
        });

        $container->singleton(AssetManagerContract::class, static fn (Container $container): AssetManager
            => $container->make(AssetManager::class));

        $container->singleton(TemplateResolver::class, static function (Container $container): TemplateResolver {
            $application = $container->make(ApplicationContract::class);
            $config = $application->config();
            $roots = [];

            foreach ((array) $config->get('view.paths', ['views']) as $path) {
                $path = (string) $path;

                if ($path === '') {
                    continue;
                }

                $roots[] = self::isAbsolute($path) ? rtrim($path, '/') : $application->basePath($path);
            }

            if ($roots === []) {
                throw new \Sahil\View\ViewException(
                    'No view directories are configured; set view.paths in config/view.php.',
                );
            }

            return new TemplateResolver(
                $roots,
                (string) $config->get('view.extension', '.sahil.php'),
            );
        });

        $container->singleton(TemplateCompiler::class, static function (Container $container): TemplateCompiler {
            $application = $container->make(ApplicationContract::class);
            $cache = (string) $application->config()->get('view.cache', 'storage/framework/views');

            return new TemplateCompiler(
                self::isAbsolute($cache) ? rtrim($cache, '/') : $application->basePath($cache),
            );
        });

        $container->singleton(ViewContract::class, static function (Container $container): ViewEngine {
            $assets = $container->bound(AssetManagerContract::class)
                ? $container->make(AssetManagerContract::class)
                : null;

            return new ViewEngine(
                $container->make(TemplateResolver::class),
                $container->make(TemplateCompiler::class),
                $assets,
            );
        });

        $container->singleton(MailManager::class, static function (Container $container): MailManager {
            $application = $container->make(ApplicationContract::class);
            $config = $application->config();

            return new MailManager(
                (array) $config->get('mail.transports', []),
                (string) $config->get('mail.default', 'array'),
                (array) $config->get('mail.from', []),
                (bool) $config->get('mail.queue.enabled', false),
                (string) $config->get('mail.queue.name', 'emails'),
                (int) $config->get('mail.queue.delay', 0),
                $container->has(LoggerContract::class) ? $container->make(LoggerContract::class) : null,
            );
        });

        $container->alias(MailerContract::class, MailManager::class);
        $container->alias('mail', MailManager::class);
        $container->alias('mailer', MailManager::class);

        $container->singleton(Router::class, static function () use ($routesFile): Router {
            /** @var mixed $definitions */
            $definitions = require $routesFile;

            if (!is_array($definitions)) {
                throw new RuntimeException(
                    'routes/web.php must return an array of Sahil\Http\Route objects.',
                );
            }

            $router = new Router();

            foreach ($definitions as $definition) {
                if (!$definition instanceof Route) {
                    throw new RuntimeException(sprintf(
                        'routes/web.php must return Sahil\Http\Route entries, got %s.',
                        get_debug_type($definition),
                    ));
                }

                $router->register($definition);
            }

            return $router;
        });

        $container->singleton(Kernel::class, static function (Container $container): Kernel {
            $application = $container->make(ApplicationContract::class);

            return (new Kernel($container->make(Router::class), $container, $application))
                ->useDebugMode($application->config()->get('app.debug') === true)
                ->using($container->make(StartSession::class))
                ->using($container->make(VerifyCsrfToken::class))
                ->using(new RequestIdMiddleware());
        });
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            || str_starts_with($path, '\\\\');
    }
}
