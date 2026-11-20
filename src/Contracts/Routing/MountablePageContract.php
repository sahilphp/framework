<?php

declare(strict_types=1);

namespace Sahil\Contracts\Routing;

use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * A self-contained page that mounts directly on the route table.
 *
 * Pages own their lifecycle — state, composed components, view and title —
 * so no controller sits between the route and the page. Firefiber's
 * `Sahil\Firefiber\Page` implements this contract; `Link::firefiber()`
 * mounts any implementation as a GET route.
 */
interface MountablePageContract
{
    /**
     * Default absolute HTTP path for the page, used when
     * `Link::firefiber()` is called without an explicit path template.
     */
    public function path(): string;

    /**
     * Render the page for the matched route; `$params` carries the route's
     * placeholder values (`/posts/{id}` → `['id' => '…']`).
     *
     * @param array<string, scalar> $params
     */
    public function show(Request $request, array $params = []): Response;
}
