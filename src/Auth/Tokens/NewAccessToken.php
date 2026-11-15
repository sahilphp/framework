<?php

declare(strict_types=1);

namespace Sahil\Auth\Tokens;

use JsonSerializable;

/**
 * The result of `createToken()` — the ONLY place the plaintext secret
 * ever exists. `jsonSerialize` deliberately omits it: logging or
 * serializing a NewAccessToken can never leak the secret.
 */
final class NewAccessToken implements JsonSerializable
{
    public function __construct(
        public readonly PersonalAccessToken $accessToken,
        public readonly string $plainTextToken,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['accessToken' => $this->accessToken->jsonSerialize()];
    }
}
