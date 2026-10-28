<?php

declare(strict_types=1);

namespace Sahil\Tests\Orm;

use Sahil\Orm\Model;
use Sahil\Orm\ModelQuery;
use Sahil\Orm\Relation;

final class OrmRole extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'orm_roles';

    protected array $fillable = ['label'];
}
