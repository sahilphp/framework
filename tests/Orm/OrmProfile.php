<?php

declare(strict_types=1);

namespace Sahil\Tests\Orm;

use Sahil\Orm\Model;
use Sahil\Orm\Relation;

final class OrmProfile extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'orm_profiles';

    protected array $fillable = ['user_id', 'bio'];

    public function user(): Relation
    {
        return $this->belongsTo(OrmUser::class, 'user_id');
    }
}
