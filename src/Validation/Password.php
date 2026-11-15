<?php

declare(strict_types=1);

namespace Sahil\Validation;

use InvalidArgumentException;

/**
 * Configurable password-strength rule.
 *
 * The bare `password` descriptor applies the framework default policy —
 * at least 8 characters with at least one letter and one digit, matching
 * the requirements the auth scaffold has always enforced. `password:N`
 * overrides the minimum length:
 *
 *     'password' => 'required|string|password',        // default policy
 *     'password' => 'required|string|password:12',     // longer minimum
 *
 * For anything more demanding compose the object form — it is an ordinary
 * {@see RuleContract}, so it slots into any rule list:
 *
 *     use Sahil\Validation\Password;
 *
 *     'password' => ['required', Password::min(12)->letters()->numbers()
 *         ->mixedCase()->symbols()],
 *
 * The rule never re-stores or echoes the secret: failure messages describe
 * the unmet requirements only.
 */
final class Password implements RuleContract
{
    public const DEFAULT_MIN = 8;

    private int $min = self::DEFAULT_MIN;

    private int $max = 255;

    private bool $letters = true;

    private bool $numbers = true;

    private bool $mixedCase = false;

    private bool $symbols = false;

    private function __construct()
    {
    }

    /**
     * Parse the string descriptor: `password` or `password:min`.
     */
    public static function fromDescriptor(string $descriptor): self
    {
        $rule = new self();

        if ($descriptor === 'password') {
            return $rule;
        }

        $argument = substr($descriptor, 9);

        if ($argument === '' || preg_match('/^\d+$/', $argument) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s] accepts only a numeric minimum, e.g. password:12.', $descriptor),
            );
        }

        $rule->min = (int) $argument;

        return $rule;
    }

    /**
     * Start a policy with an explicit minimum length.
     */
    public static function min(int $min): self
    {
        if ($min < 1) {
            throw new InvalidArgumentException('Password minimum length must be at least 1.');
        }

        $rule = new self();
        $rule->min = $min;

        return $rule;
    }

    public function max(int $max): self
    {
        if ($max < $this->min) {
            throw new InvalidArgumentException(
                sprintf('Password maximum [%d] is below the minimum [%d].', $max, $this->min),
            );
        }

        $rule = clone $this;
        $rule->max = $max;

        return $rule;
    }

    /**
     * Require at least one letter. Enabled by the default policy.
     */
    public function letters(bool $require = true): self
    {
        $rule = clone $this;
        $rule->letters = $require;

        return $rule;
    }

    /**
     * Require at least one digit. Enabled by the default policy.
     */
    public function numbers(bool $require = true): self
    {
        $rule = clone $this;
        $rule->numbers = $require;

        return $rule;
    }

    /**
     * Require at least one lowercase and one uppercase letter.
     */
    public function mixedCase(): self
    {
        $rule = clone $this;
        $rule->mixedCase = true;
        $rule->letters = true;

        return $rule;
    }

    /**
     * Require at least one non-alphanumeric character.
     */
    public function symbols(): self
    {
        $rule = clone $this;
        $rule->symbols = true;

        return $rule;
    }

    public function validate(string $field, mixed $value): ?string
    {
        if (!is_string($value)) {
            return 'This field must be a string.';
        }

        $failures = [];

        $length = mb_strlen($value);

        if ($length < $this->min) {
            $failures[] = sprintf('at least %d characters', $this->min);
        }

        if ($length > $this->max) {
            $failures[] = sprintf('at most %d characters', $this->max);
        }

        if ($this->letters && preg_match('/\pL/u', $value) !== 1) {
            $failures[] = 'a letter';
        }

        if ($this->numbers && preg_match('/\d/', $value) !== 1) {
            $failures[] = 'a number';
        }

        if ($this->mixedCase
            && (preg_match('/\p{Ll}/u', $value) !== 1 || preg_match('/\p{Lu}/u', $value) !== 1)) {
            $failures[] = 'both lowercase and uppercase letters';
        }

        if ($this->symbols && preg_match('/[^\pL\pN]/u', $value) !== 1) {
            $failures[] = 'a symbol';
        }

        return $failures === []
            ? null
            : 'This field needs '.self::listRequirements($failures).'.';
    }

    /**
     * @param list<string> $requirements
     */
    private static function listRequirements(array $requirements): string
    {
        $last = array_pop($requirements);

        return $requirements === []
            ? (string) $last
            : implode(', ', $requirements).' and '.$last;
    }
}
