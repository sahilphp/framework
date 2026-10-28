<?php

declare(strict_types=1);

namespace Sahil\Tests\Orm;

use Sahil\Orm\Model;
use Sahil\Orm\ModelQuery;
use Sahil\Orm\Relation;

final class OrmPost extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'orm_posts';

    protected array $fillable = ['user_id', 'title'];

    public function author(): Relation
    {
        return $this->belongsTo(OrmUser::class, 'user_id');
    }
}
