<?php

declare(strict_types=1);

namespace Sahil\Rendering;

use InvalidArgumentException;
use RuntimeException;
use Sahil\Contracts\Rendering\RenderAdapterContract;
use Sahil\Http\Response;

/**
 * Central rendering orchestration.
 *
 * Named environments resolve to {@see RenderAdapterContract}s; the manager
 * routes a page identifier + data + status to the right one. `html` is
 * always available; UI kits register their own adapters at boot
 * (`vue`, `react`, `svelte`, `fire`, ...), so the core never depends on
 * frontend packages.
 *
 * The manager is deliberately request-agnostic: adapters receive only the
 * page name and data, so nothing request-specific can leak between
 * long-running worker requests.
 */
final class RenderManager
{
    /** @var array<string, RenderAdapterContract> */
    private array $adapters = [];

    /**
     * @param iterable<RenderAdapterContract> $adapters
     */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(RenderAdapterContract $adapter): void
    {
        $name = $adapter->environment();

        if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Render environment name [%s] must be lower-case letters, digits and dashes.',
                $name,
            ));
        }

        if (isset($this->adapters[$name])) {
            throw new InvalidArgumentException(sprintf(
                'A render adapter for environment [%s] is already registered.',
                $name,
            ));
        }

        $this->adapters[$name] = $adapter;
    }

    public function has(string $environment): bool
    {
        return isset($this->adapters[$environment]);
    }

    /** @return list<string> */
    public function environments(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Render a page through a named environment.
     *
     * @param array<string, mixed> $data
     *
     * @throws RuntimeException When the environment has no adapter
     */
    public function render(string $environment, string $page, array $data = [], int $status = 200): Response
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(sprintf(
                'HTTP status %d is not a valid response status.',
                $status,
            ));
        }

        $adapter = $this->adapters[$environment] ?? throw new RuntimeException(sprintf(
            'No render adapter for environment [%s]%s.',
            $environment,
            $this->adapters === [] ? '' : '; available: '.implode(', ', array_keys($this->adapters)),
        ));

        return $adapter->render($page, $data, $status);
    }
}
