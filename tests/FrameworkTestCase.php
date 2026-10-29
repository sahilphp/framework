<?php

declare(strict_types=1);

namespace Sahil\Tests;

use Sahil\Testing\ApplicationTestCase;

/**
 * Base class for framework-owned application tests.
 *
 * The framework package is a library, not a complete application. Its tests
 * therefore boot the controlled fixture at `tests/Fixtures/app-root` rather
 * than the package root or the starter-kit. Application tests that need a
 * real installation must override {@see basePath()} themselves.
 *
 * The fixture includes a test-only console harness for the standalone UI
 * commands. It is not a package executable; the starter-kit owns the real
 * application entry point.
 */
abstract class FrameworkTestCase extends ApplicationTestCase
{
    protected function basePath(): string
    {
        return __DIR__.'/Fixtures/app-root';
    }

    protected function consoleBinary(): string
    {
        return __DIR__.'/Fixtures/console.php';
    }
}
