<?php

declare(strict_types=1);

namespace Sahil\Rendering;

use RuntimeException;
use Sahil\Contracts\View\ViewContract;
use Sahil\Foundation\Application;
use Sahil\Http\Response;
use Sahil\Rendering\Adapters\HtmlAdapter;

/**
 * Render — the unified page-rendering entry point.
 *
 *     Render::to('vue', 'pages.school.index', $data);
 *     Render::html('pages.school.index', $data);
 *     Render::vue('pages.school.index', $data);   // when a vue adapter is bound
 *
 * The manager resolves through the container (`RenderManager` bound by a
 * provider, e.g. each UI kit's `FrontendServiceProvider`); without a
 * binding a default manager carrying only the native `html` adapter is
 * used. Same convention as `Bouncer`/`RateLimiter`/`Notify`.
 */
final class Render
{
    private static ?RenderManager $instance = null;

    private function __construct()
    {
    }

    /** Render through a named environment (`html`, `vue`, `fire`, ...). */
    public static function to(string $environment, string $page, array $data = [], int $status = 200): Response
    {
        return self::manager()->render($environment, $page, $data, $status);
    }

    public static function html(string $page, array $data = [], int $status = 200): Response
    {
        return self::to('html', $page, $data, $status);
    }

    public static function vue(string $page, array $data = [], int $status = 200): Response
    {
        return self::to('vue', $page, $data, $status);
    }

    public static function react(string $page, array $data = [], int $status = 200): Response
    {
        return self::to('react', $page, $data, $status);
    }

    public static function svelte(string $page, array $data = [], int $status = 200): Response
    {
        return self::to('svelte', $page, $data, $status);
    }

    /** Canonical Firefiber environment. */
    public static function fire(string $page, array $data = [], int $status = 200): Response
    {
        return self::to('fire', $page, $data, $status);
    }

    /** Firefiber alias — identical to {@see fire()}. */
    public static function fiber(string $page, array $data = [], int $status = 200): Response
    {
        return self::to('fiber', $page, $data, $status);
    }

    public static function manager(): RenderManager
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $application = Application::peek();

        // bound() not has(): RenderManager is a concrete class, so has() is
        // always true and make() would autowire a manager with zero adapters.
        if ($application !== null && $application->container()->bound(RenderManager::class)) {
            /** @var RenderManager $manager */
            $manager = $application->container()->make(RenderManager::class);

            return $manager;
        }

        // No binding: native-only manager when a view engine is available.
        if ($application !== null && $application->container()->has(ViewContract::class)) {
            return new RenderManager([new HtmlAdapter($application->container()->make(ViewContract::class))]);
        }

        throw new RuntimeException(
            'Render needs a configured application with a bound RenderManager or ViewContract — or Render::swap() for tests.',
        );
    }

    /** Install the manager the static API delegates to — tests and non-application contexts. */
    public static function swap(?RenderManager $manager): void
    {
        self::$instance = $manager;
    }

    /** Drop the swapped instance. */
    public static function flushState(): void
    {
        self::$instance = null;
    }
}
