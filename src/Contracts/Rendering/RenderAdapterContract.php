<?php

declare(strict_types=1);

namespace Sahil\Contracts\Rendering;

use Sahil\Http\Response;

/**
 * One rendering environment (`html`, `vue`, `react`, `svelte`, `fire`, ...).
 *
 * Adapters are registered on the {@see \Sahil\Rendering\RenderManager} under
 * their environment name. The core owns orchestration — resolving the page,
 * passing data, producing the response — while each adapter owns exactly one
 * environment's rendering. UI kits ship an adapter each; the framework core
 * never depends on them.
 */
interface RenderAdapterContract
{
    /** The environment name used by `Render::to()` and controller helpers. */
    public function environment(): string;

    /**
     * Render a page/component identifier into an HTTP response.
     *
     * @param array<string, mixed> $data page data/props for the renderer
     */
    public function render(string $page, array $data, int $status): Response;
}
