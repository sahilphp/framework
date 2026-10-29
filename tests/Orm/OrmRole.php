<?php

declare(strict_types=1);

namespace Sahil\Tests\Orm;

use Sahil\Orm\Model;

final class OrmRole extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'orm_roles';

    protected array $fillable = ['label'];
}
