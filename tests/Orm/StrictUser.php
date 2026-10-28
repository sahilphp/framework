<?php

declare(strict_types=1);

namespace Sahil\Tests\Orm;

/**
 * Fixture: fillable allowlist narrower than attributes, with an
 * explicitly guarded key (guard beats fillable).
 */
final class StrictUser extends OrmUser
{
    protected array $fillable = ['name'];

    protected array $guarded = ['password'];
}
