<?php

declare(strict_types=1);

namespace Sahil\Http\Concerns;

use Sahil\Http\Response;
use Sahil\Rendering\Render;

/**
 * Controller page-rendering helpers — one call returns a fully rendered
 * HTTP response for the application's configured environment:
 *
 *     return $this->html('pages.school.index', ['message' => 'Hi']);
 *     return $this->vue('pages.school.index', ['message' => 'Hi']);
 *     return $this->renderTo('my-engine', 'pages.x'); // custom envs
 *
 * Every call delegates to {@see Render} — the manager and adapter resolve
 * per call, so no request or renderer state can go stale across
 * long-running worker requests.
 */
trait RendersPages
{
    protected function html(string $page, array $data = [], int $status = 200): Response
    {
        return Render::html($page, $data, $status);
    }

    protected function vue(string $page, array $data = [], int $status = 200): Response
    {
        return Render::vue($page, $data, $status);
    }

    protected function react(string $page, array $data = [], int $status = 200): Response
    {
        return Render::react($page, $data, $status);
    }

    protected function svelte(string $page, array $data = [], int $status = 200): Response
    {
        return Render::svelte($page, $data, $status);
    }

    /** Generic escape hatch for environments the app registers itself. */
    protected function renderTo(string $environment, string $page, array $data = [], int $status = 200): Response
    {
        return Render::to($environment, $page, $data, $status);
    }
}
