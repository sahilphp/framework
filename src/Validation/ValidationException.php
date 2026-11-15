<?php

declare(strict_types=1);

namespace Sahil\Validation;

use RuntimeException;

/**
 * Raised when request validation fails through `Request::validate()`,
 * `Form::validate()` or `ValidationResult::toException()`.
 *
 * The HTTP kernel gives it first-class semantics:
 *
 *  - JSON clients get `422` with `{"message": ..., "errors": {field: [...]}}`;
 *  - browser submissions redirect back (303) with the errors flashed into
 *    the session under the exception's error bag, plus sanitized old
 *    input — the next render sees them through the shared `$errors` /
 *    `$old` view data.
 *
 * Business-level validation (e.g. "that email is already taken") throws
 * the same exception through {@see withMessages()} so it flows through
 * the exact same response path.
 */
final class ValidationException extends RuntimeException
{
    public function __construct(
        private readonly ValidationResult $result,
        private readonly ?string $bag = null,
        private readonly array $input = [],
        private readonly ?string $redirectTo = null,
    ) {
        $summary = [];

        foreach ($result->errors() as $field => $messages) {
            $summary[] = "$field: ".implode(' / ', $messages);
        }

        parent::__construct('Validation failed: '.implode('; ', $summary));
    }

    /**
     * Attach validation context to a failed result.
     *
     * @param array<string, mixed> $input the payload that was validated
     *     (kernel sanitizes it before flashing as old input)
     */
    public static function forResult(
        ValidationResult $result,
        ?string $bag = null,
        array $input = [],
        ?string $redirectTo = null,
    ): self {
        return new self($result, $bag, $input, $redirectTo);
    }

    /**
     * Build a validation failure from explicit field messages — the
     * mechanism for application-level checks that are not rule coverage,
     * e.g. a taken e-mail address.
     *
     * @param array<string, string|list<string>> $messages
     * @param array<string, mixed> $input
     */
    public static function withMessages(
        array $messages,
        ?string $bag = null,
        array $input = [],
        ?string $redirectTo = null,
    ): self {
        $errors = [];

        foreach ($messages as $field => $list) {
            $errors[(string) $field] = is_array($list) ? array_values($list) : [$list];
        }

        return new self(new ValidationResult($errors, []), $bag, $input, $redirectTo);
    }

    public function result(): ValidationResult
    {
        return $this->result;
    }

    /**
     * Named error bag the failures belong to; null = the default bag.
     */
    public function bag(): ?string
    {
        return $this->bag;
    }

    /**
     * The validated payload (raw; the kernel sanitizes before flashing).
     *
     * @return array<string, mixed>
     */
    public function input(): array
    {
        return $this->input;
    }

    /**
     * Explicit redirect target for the failure, when the application
     * overrides the default "back" behaviour.
     */
    public function redirectTo(): ?string
    {
        return $this->redirectTo;
    }
}
