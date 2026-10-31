<?php

declare(strict_types=1);

namespace Sahil\Tests\Frontend;

use Sahil\Frontend\FrontendAdapterContract;
use Sahil\Frontend\FrontendAssets;
use Sahil\View\RenderedView;

/**
 * Test double for a Vue/React/Svelte integration: it records what the
 * renderer asked for, so the shared pipeline can be tested without any
 * JavaScript toolchain.
 */
final class RecordingFrontendAdapter implements FrontendAdapterContract
{
    /** @var list<bool> */
    public array $assetModes = [];

    /**
     * @param array<string, bool|int|float|string> $attributes
     */
    public function __construct(
        private readonly string $target = 'vue',
        private readonly string $component = 'Pages/Home.vue',
        private readonly array $attributes = ['data-framework' => 'vue'],
        private readonly ?string $script = 'http://127.0.0.1:5173/resources/js/app.js',
        private readonly ?string $style = '/build/assets/app.css',
    ) {
    }

    public function name(): string
    {
        return $this->target;
    }

    public function component(RenderedView $view): string
    {
        return $this->component;
    }

    public function payload(RenderedView $view): array
    {
        return $view->data();
    }

    public function mountAttributes(RenderedView $view): array
    {
        return $this->attributes;
    }

    public function assets(bool $development): FrontendAssets
    {
        $this->assetModes[] = $development;

        return new FrontendAssets(
            $this->script === null ? [] : [$this->script],
            $this->style === null ? [] : [$this->style],
        );
    }
}
