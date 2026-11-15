<?php

declare(strict_types=1);

namespace Sahil\Tests\Auth;

use Sahil\Auth\Authorizable;
use Sahil\Auth\Tokens\HasApiTokens;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Orm\Model;

final class TokenUser extends Model implements AuthenticatableContract
{
    use Authorizable;
    use HasApiTokens;

    protected ?string $table = 'users';

    public function __construct(array $attributes = [], bool $exists = false)
    {
        if ($exists) {
            foreach ($attributes as $key => $value) {
                $this->setRawAttribute((string) $key, $value, false);
            }
            return;
        }
        parent::__construct($attributes);
    }

    public function getAuthIdentifier(): int|string
    {
        return (int) $this->getAttribute('id');
    }

    public function getAuthPassword(): string
    {
        return (string) $this->getAttribute('password');
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }
}
