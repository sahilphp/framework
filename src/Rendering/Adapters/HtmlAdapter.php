<?php

declare(strict_types=1);

namespace Sahil\Rendering\Adapters;

use Sahil\Contracts\Rendering\RenderAdapterContract;
use Sahil\Contracts\View\ViewContract;
use Sahil\Http\Response;

/**
 * Native Sahil Engine rendering: resolves the template through
 * {@see ViewContract} and returns the rendered view's response — the
 * `RenderedView` stays attached for downstream adapters.
 */
final class HtmlAdapter implements RenderAdapterContract
{
    public function __construct(
        private readonly ViewContract $views,
    ) {
    }

    public function environment(): string
    {
        return 'html';
    }

    public function render(string $page, array $data, int $status): Response
    {
        return Response::html($this->views->renderView($page, $data), $status);
    }
}
