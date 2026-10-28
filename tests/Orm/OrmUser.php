<?php

declare(strict_types=1);

namespace Sahil\Tests\Orm;

use Sahil\Orm\Model;
use Sahil\Orm\ModelQuery;
use Sahil\Orm\Relation;

class OrmUser extends Model
{
    protected ?string $table = 'orm_users';

    protected string $primaryKey = 'id';

    protected array $fillable = ['name', 'email', 'password', 'active'];

    protected array $hidden = ['password'];

    protected array $casts = ['active' => 'bool'];

    public function profile(): Relation
    {
        return $this->hasOne(OrmProfile::class, 'user_id');
    }

    public function posts(): Relation
    {
        return $this->hasMany(OrmPost::class, 'user_id');
    }

    public function roles(): Relation
    {
        return $this->belongsToMany(OrmRole::class, 'orm_user_roles', 'user_id', 'role_id');
    }

    public function scopeActive(ModelQuery $query): void
    {
        $query->where('active', '=', 1);
    }

    public function scopeInactive(ModelQuery $query): void
    {
        $query->where('active', '=', 0);
    }
}
