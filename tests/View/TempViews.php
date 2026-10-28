<?php

declare(strict_types=1);

namespace Sahil\Tests\View;

/**
 * Gives a test one or more {@see TempViewWorkspace} instances that are
 * always removed when the test finishes, including on failure.
 */
trait TempViews
{
    /** @var list<TempViewWorkspace> */
    private array $workspaces = [];

    private function workspace(int $roots = 1, string $extension = '.sahil.php'): TempViewWorkspace
    {
        $workspace = new TempViewWorkspace($roots, $extension);
        $this->workspaces[] = $workspace;

        return $workspace;
    }

    protected function tearDown(): void
    {
        $this->tearDownTempViews();
    }

    protected function tearDownTempViews(): void
    {
        foreach ($this->workspaces as $workspace) {
            $workspace->destroy();
        }

        $this->workspaces = [];
    }
}
