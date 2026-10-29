<?php

declare(strict_types=1);

/**
 * Test-only provider manifest for the framework application fixture.
 *
 * Framework-owned tests boot this graph instead of the package root or the
 * starter-kit. Real installations keep their own manifest.
 *
 * @return list<class-string<\Sahil\Foundation\ServiceProvider>>
 */
return [
    \Sahil\Tests\Fixtures\Providers\FixtureProvider::class,
];
