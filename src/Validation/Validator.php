<?php

declare(strict_types=1);

namespace Sahil\Validation;

use Closure;
use InvalidArgumentException;
use Traversable;

/**
 * Deterministic rule-based validator.
 *
 * Construction:
 *
 *     $validator = new Validator([
 *         'email' => 'required|email',
 *         'age' => 'nullable|integer|min:0|max:130',
 *         'user.name' => 'required|string',
 *         'role' => 'in:admin,editor',
 *         'code' => 'regex:/^[A-Z]{3}$/',
 *         'tags.*' => 'string',
 *     ]);
 *
 * Conventions (documented as API contract):
 *
 *  - Fields not mentioned in the rule map NEVER reach `validated()`:
 *    validated data is the only "accepted" shape, raw input never is.
 *  - `required` fails for missing / null / empty-string fields.
 *  - `nullable` lets missing / null fields skip the whole chain; without
 *    `required` a missing field passes silently (validates to nothing).
 *  - An empty string is a PRESENT value: it is judged by the chain (so
 *    `numeric` rejects `""`), only `required` treats it as missing.
 *  - A failing field stops its own chain at the first failure (no
 *    cascading garbage messages), other fields keep validating.
 *  - Type rules check strictly; the ONLY coercion is documented and
 *    lossless: `integer` accepts int or plain digit strings → int,
 *    `numeric` accepts int/float/numeric strings (digit strings stay
 *    int when lossless, otherwise float), `boolean` accepts real bools
 *    or the exact strings "true"/"false" → bool. No trimming, no
 *    "looks like" heuristics beyond these cases.
 *  - Strings containing control characters (NUL, C0 controls except
 *    \t\n\r, DEL, C1) fail the `string` rule unless the SAME rule set
 *    also contains `allowsControlChars`.
 *  - Normalization happens ONLY when explicitly configured via
 *    {@see normalize()} (currently `trim`); default judges raw input.
 *
 * Wildcards (`tags.*`) clone the rules onto every element of the parent
 * array at that nesting level; nested dot paths reach array children.
 */
final class Validator
{
    private const CONTROL_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}]/u';

    /** Hard bound on wildcard-expanded rule paths — rejects pathological input shapes. */
    private const MAX_EXPANDED_RULES = 10_000;

    /** @var array<string, string|array<int, string|Closure|RuleContract>> */
    private array $rules;

    /** @var array<string, string> */
    private array $messages;

    /** @var list<string> */
    private array $normalizers = [];

    /**
     * @param array<string, string|array<int, string|Closure|RuleContract>> $rules
     * @param array<string, string> $messages custom messages; keys are
     *     `field.rule`, `field` or bare `rule`
     */
    public function __construct(array $rules, array $messages = [])
    {
        $this->rules = $rules;
        $this->messages = $messages;
    }

    /**
     * One-call validation — the facade-style entry point:
     *
     *     $result = Validator::make($data, ['email' => 'required|email']);
     *     $result->fails() / ->errors() / ->data() / ->first() / ->errorsFor('email')
     *
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string|Closure|RuleContract>> $rules
     * @param array<string, string> $messages
     */
    public static function make(array $data, array $rules, array $messages = []): ValidationResult
    {
        return (new self($rules, $messages))->validate($data);
    }

    /**
     * Explicit, opt-in normalization applied to input strings BEFORE
     * checks. Supported operations: `trim`. Default: none.
     *
     * @param list<string>|string $operations
     */
    public function normalize(array|string $operations): self
    {
        $operations = (array) $operations;
        $supported = ['trim'];

        foreach ($operations as $operation) {
            if (!in_array($operation, $supported, true)) {
                throw new InvalidArgumentException(
                    sprintf('Unknown input normalization [%s]; supported: %s.', $operation, implode(', ', $supported)),
                );
            }
        }

        $clone = clone $this;
        $clone->normalizers = $operations;

        return $clone;
    }

    /**
     * Run every rule over every field and collect all messages.
     *
     * @param array<string, mixed> $data
     */
    public function validate(array $data): ValidationResult
    {
        $errors = [];
        $validatedPaths = [];

        foreach ($this->expandedRules($data) as $path => $ruleSet) {
            $outcome = $this->runChain($path, $ruleSet, $data);

            if ($outcome['messages'] === []) {
                if ($outcome['hasOutputValue']) {
                    $validatedPaths[$path] = $outcome['value'];
                }

                continue;
            }

            $errors[$path] = $outcome['messages'];
        }

        return new ValidationResult($errors, $validatedPaths);
    }

    /**
     * Expand wildcard rules onto concrete array paths.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, list<string|Closure|RuleContract>>
     */
    private function expandedRules(array $data): array
    {
        $expanded = [];

        foreach ($this->rules as $path => $ruleSet) {
            $ruleList = $this->ruleList($ruleSet);

            if (!str_contains($path, '.*')) {
                $expanded[$path] = $ruleList;

                continue;
            }

            foreach ($this->explodeWildcard($path, $data) as $concrete) {
                $expanded[$concrete] = $ruleList;

                if (count($expanded) > self::MAX_EXPANDED_RULES) {
                    throw new InvalidArgumentException(sprintf(
                        'Wildcard rule expansion exceeded %d fields; narrow the rule set or the input shape.',
                        self::MAX_EXPANDED_RULES,
                    ));
                }
            }
        }

        return $expanded;
    }

    /**
     * Turn `parent.*.child` into concrete paths by walking arrays; only
     * existing paths are produced (missing parents expand to nothing —
     * the lookup treats them as absent).
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function explodeWildcard(string $path, array $data): array
    {
        /** @var list<string> $concrete */
        $concrete = [''];
        $sawWildcard = false;

        foreach (explode('.', $path) as $segment) {
            $next = [];

            foreach ($concrete as $prefix) {
                if ($segment === '*') {
                    $sawWildcard = true;
                    $node = $prefix === '' ? $data : self::pathLookup($data, $prefix)[1];

                    if (is_array($node) || $node instanceof Traversable) {
                        foreach ($node as $key => $_) {
                            $next[] = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                        }
                    }

                    continue;
                }

                $next[] = $prefix === '' ? $segment : $prefix.'.'.$segment;
            }

            $concrete = $next;
        }

        return $sawWildcard ? $concrete : [];
    }

    /**
     * @param string|array<int, mixed> $ruleSet elements are string
     *     descriptors, Closure or RuleContract; anything else fails loudly
     *
     * @return list<string|Closure|RuleContract>
     */
    private function ruleList(string|array $ruleSet): array
    {
        if (is_string($ruleSet)) {
            return array_values(array_filter(
                array_map(trim(...), self::splitRules($ruleSet)),
                static fn (string $rule): bool => $rule !== '',
            ));
        }

        $rules = [];

        foreach ($ruleSet as $key => $rule) {
            if ($rule instanceof RuleContract) {
                $rules[] = $rule;

                continue;
            }

            if ($rule instanceof Closure) {
                $rules[] = $rule;

                continue;
            }

            if (is_string($rule)) {
                $rules[] = $rule;

                continue;
            }

            throw new InvalidArgumentException(
                sprintf('Validation rule for [%s] must be a string descriptor, Closure or RuleContract.', $key),
            );
        }

        return $rules;
    }

    /**
     * Split a `a|b|c` descriptor on UNESCAPED pipes: `regex:/a|b/` written
     * as `regex:/a\|b/` stays one rule. A `|` preceded by an odd number of
     * backslashes is literal; the escape is unwrapped per token.
     *
     * @return list<string>
     */
    private static function splitRules(string $ruleSet): array
    {
        $rules = [];
        $current = '';
        $length = strlen($ruleSet);

        for ($i = 0; $i < $length; $i++) {
            $char = $ruleSet[$i];

            if ($char === '|') {
                // Count the backslashes immediately before the delimiter.
                $slashes = 0;
                for ($j = $i - 1; $j >= 0 && $current !== '' && $current[strlen($current) - 1 - $slashes] === '\\'; $slashes++) {
                }

                if ($slashes % 2 === 1) {
                    // Literal pipe: drop one escaping backslash.
                    $current = substr($current, 0, -1).'|';

                    continue;
                }

                $rules[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $rules[] = $current;

        return $rules;
    }

    /**
     * Run the rule chain for one concrete field path.
     *
     * @param list<string|Closure|RuleContract> $ruleSet
     *
     * @param array<string, mixed> $data
     *
     * @return array{messages: list<string>, hasOutputValue: bool, value: mixed}
     */
    private function runChain(string $path, array $ruleSet, array $data): array
    {
        [$exists, $value] = $this->pathLookup($data, $path);
        $missing = !$exists || $value === null;

        // `missing`: the attribute must NOT be present at all.
        if (in_array('missing', $ruleSet, true)) {
            return [
                'messages' => $exists
                    ? [$this->message($path, 'missing', 'This field must not be present.', 'missing')]
                    : [],
                'hasOutputValue' => false,
                'value' => null,
            ];
        }

        // `present`: the key must exist; a null value is acceptable input.
        if (!$exists && in_array('present', $ruleSet, true)) {
            return [
                'messages' => [$this->message($path, 'present', 'This field must be present.', 'present')],
                'hasOutputValue' => false,
                'value' => null,
            ];
        }

        // `filled`: when the key is present the value must not be empty;
        // an absent key is fine (combine with `required` to require it).
        if (in_array('filled', $ruleSet, true)) {
            if (!$exists) {
                return ['messages' => [], 'hasOutputValue' => false, 'value' => null];
            }

            if ($value === null || $value === '' || $value === []) {
                return [
                    'messages' => [$this->message($path, 'filled', 'This field must have a value.', 'filled')],
                    'hasOutputValue' => false,
                    'value' => null,
                ];
            }
        }

        $required = in_array('required', $ruleSet, true)
            || $this->conditionallyRequired($ruleSet, $data);

        if ($missing) {
            // `sometimes`: a wholly absent field skips its ENTIRE chain —
            // required included. A present null still counts as input and
            // follows the ordinary missing/null contract below.
            if (!$exists && in_array('sometimes', $ruleSet, true)) {
                return ['messages' => [], 'hasOutputValue' => false, 'value' => null];
            }

            if ($required) {
                return [
                    'messages' => [$this->message($path, 'required', 'This field is required.', 'required')],
                    'hasOutputValue' => false,
                    'value' => null,
                ];
            }

            // Optional missing/null fields pass, but validate to nothing.
            return ['messages' => [], 'hasOutputValue' => false, 'value' => null];
        }

        if ($value === '' && $required) {
            return [
                'messages' => [$this->message($path, 'required', 'This field is required.', 'required')],
                'hasOutputValue' => false,
                'value' => null,
            ];
        }

        // Explicit, configured normalization only.
        foreach ($this->normalizers as $operation) {
            if ($operation === 'trim' && is_string($value)) {
                $value = trim($value);
            }
        }

        $allowsControlChars = in_array('allowsControlChars', $ruleSet, true);
        $messages = [];

        foreach ($ruleSet as $rule) {
            if (is_string($rule)) {
                if ($rule === 'nullable' || $rule === 'required' || $rule === 'allowsControlChars'
                    || $rule === 'sometimes' || $rule === 'present' || $rule === 'filled'
                    || $rule === 'missing' || str_starts_with($rule, 'required_')) {
                    continue;
                }

                $outcome = $this->runBuiltIn($rule, $path, $value, $allowsControlChars, $data);
            } elseif ($rule instanceof RuleContract) {
                $outcome = $rule->validate($path, $value);
            } else {
                /** @var Closure $rule */
                $outcome = $rule($path, $value);
            }

            if (is_array($outcome)) {
                /** @var array{0: ?string, 1: mixed} $outcome */
                $value = $outcome[1];
                $outcome = $outcome[0];
            }

            if (is_string($outcome)) {
                if (is_string($rule)) {
                    $ruleName = explode(':', $rule, 2)[0];
                    $outcome = $this->message($path, $ruleName, $outcome, $rule);
                }

                $messages[] = $outcome;

                break; // stop at the first failure for THIS field
            }
        }

        return [
            'messages' => $messages,
            'hasOutputValue' => $messages === [],
            'value' => $messages === [] ? $value : null,
        ];
    }

    /**
     * Conditional presence rules — `required_if:field,value`,
     * `required_unless:field,value`, `required_with:a,b`,
     * `required_without:a,b`. "Present" means the key exists and holds a
     * non-empty value (null, '' and [] all count as absent input).
     *
     * @param list<string|Closure|RuleContract> $ruleSet
     * @param array<string, mixed> $data
     */
    private function conditionallyRequired(array $ruleSet, array $data): bool
    {
        foreach ($ruleSet as $rule) {
            if (!is_string($rule)) {
                continue;
            }

            if (str_starts_with($rule, 'required_if:')) {
                $other = substr($rule, 12);

                if (!str_contains($other, ',')) {
                    throw new InvalidArgumentException(
                        sprintf('Validation rule [%s] needs `required_if:field,value`.', $rule),
                    );
                }

                [$field, $expected] = explode(',', $other, 2);
                [$otherExists, $otherValue] = self::pathLookup($data, $field);

                if ($otherExists && $otherValue === $expected) {
                    return true;
                }
            } elseif (str_starts_with($rule, 'required_unless:')) {
                $other = substr($rule, 16);

                if (!str_contains($other, ',')) {
                    throw new InvalidArgumentException(
                        sprintf('Validation rule [%s] needs `required_unless:field,value`.', $rule),
                    );
                }

                [$field, $expected] = explode(',', $other, 2);
                [$otherExists, $otherValue] = self::pathLookup($data, $field);

                if (!($otherExists && $otherValue === $expected)) {
                    return true;
                }
            } elseif (str_starts_with($rule, 'required_with:')) {
                foreach (explode(',', substr($rule, 14)) as $field) {
                    [$otherExists, $otherValue] = self::pathLookup($data, $field);

                    if ($otherExists && $otherValue !== null && $otherValue !== '' && $otherValue !== []) {
                        return true;
                    }
                }
            } elseif (str_starts_with($rule, 'required_without:')) {
                foreach (explode(',', substr($rule, 17)) as $field) {
                    [$otherExists, $otherValue] = self::pathLookup($data, $field);

                    if (!($otherExists && $otherValue !== null && $otherValue !== '' && $otherValue !== [])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Built-in rules. Return types:
     *   ?string                        verdict only (null = pass)
     *   array{0: ?string, 1: mixed}    verdict + (possibly cast) validated value
     *
     * @param array<string, mixed> $data the full payload (cross-field rules)
     *
     * @return string|array{0: ?string, 1: mixed}|null
     */
    private function runBuiltIn(string $rule, string $path, mixed $value, bool $allowsControlChars, array $data): string|array|null
    {
        if (str_starts_with($rule, 'min:')) {
            return self::sizeBound('min', $rule, $value);
        }

        if (str_starts_with($rule, 'max:')) {
            return self::sizeBound('max', $rule, $value);
        }

        if (str_starts_with($rule, 'between:')) {
            return self::betweenRule($rule, $value);
        }

        if (str_starts_with($rule, 'length:')) {
            return self::lengthRule($rule, $value);
        }

        if (str_starts_with($rule, 'size:')) {
            return self::sizeRule($rule, $value);
        }

        if (str_starts_with($rule, 'digits_between:')) {
            return self::digitsBetweenRule($rule, $value);
        }

        if (str_starts_with($rule, 'digits:')) {
            return self::digitsRule($rule, $value);
        }

        if (str_starts_with($rule, 'date_format:')) {
            return self::dateFormatRule($rule, $value);
        }

        if (str_starts_with($rule, 'before_or_equal:')) {
            return self::dateComparison('before_or_equal', substr($rule, 17), $value);
        }

        if (str_starts_with($rule, 'after_or_equal:')) {
            return self::dateComparison('after_or_equal', substr($rule, 15), $value);
        }

        if (str_starts_with($rule, 'mimetypes:')) {
            return self::fileRule('mimetypes', explode(',', substr($rule, 10)), $value);
        }

        if (str_starts_with($rule, 'mimes:')) {
            return self::fileRule('mimes', explode(',', substr($rule, 6)), $value);
        }

        if (str_starts_with($rule, 'extensions:')) {
            return self::fileRule('extensions', explode(',', substr($rule, 11)), $value);
        }

        if (str_starts_with($rule, 'in:')) {
            return self::membership(inclusion: true, rule: $rule, value: $value);
        }

        if (str_starts_with($rule, 'notIn:') || str_starts_with($rule, 'not_in:')) {
            return self::membership(inclusion: false, rule: $rule, value: $value);
        }

        if (str_starts_with($rule, 'regex:')) {
            return self::pattern($rule, $path, $value);
        }

        if ($rule === 'confirmed' || str_starts_with($rule, 'confirmed:')) {
            return $this->confirmedRule($rule, $path, $value, $data);
        }

        if (str_starts_with($rule, 'same:')) {
            return $this->comparisonRule('same', substr($rule, 5), $value, $data);
        }

        if (str_starts_with($rule, 'different:')) {
            return $this->comparisonRule('different', substr($rule, 10), $value, $data);
        }

        if ($rule === 'password' || str_starts_with($rule, 'password:')) {
            return Password::fromDescriptor($rule)->validate($path, $value);
        }

        if (str_starts_with($rule, 'before:')) {
            return self::dateComparison('before', substr($rule, 7), $value);
        }

        if (str_starts_with($rule, 'after:')) {
            return self::dateComparison('after', substr($rule, 6), $value);
        }

        return match ($rule) {
            'string' => self::checkString($value, $allowsControlChars),
            'array' => is_array($value) ? null : 'This field must be an array.',
            'integer' => self::integerCast($value),
            'numeric' => self::numericCast($value),
            'boolean' => self::booleanCast($value),
            'email' => (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false)
                ? null
                : 'This field must be a valid email address.',
            'url' => (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false)
                ? null
                : 'This field must be a valid URL.',
            'accepted' => self::accepted($value),
            'date' => self::dateRule($value),
            'ip' => (is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false)
                ? null
                : 'This field must be a valid IP address.',
            'uuid' => (is_string($value) && preg_match(
                '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
                $value,
            ) === 1)
                ? null
                : 'This field must be a valid UUID.',
            'json' => self::jsonRule($value),
            'alpha' => (is_string($value) && $value !== '' && preg_match('/^\p{L}+$/u', $value) === 1)
                ? null
                : 'This field may only contain letters.',
            'alpha_num' => (is_string($value) && $value !== '' && preg_match('/^[\p{L}\p{N}]+$/u', $value) === 1)
                ? null
                : 'This field may only contain letters and numbers.',
            'alpha_dash' => (is_string($value) && $value !== '' && preg_match('/^[\p{L}\p{N}_-]+$/u', $value) === 1)
                ? null
                : 'This field may only contain letters, numbers, dashes and underscores.',
            'file' => self::fileRule('file', [], $value),
            'image' => self::fileRule('image', [], $value),
            default => throw new InvalidArgumentException(
                sprintf('Unknown validation rule [%s] on field [%s].', $rule, $path),
            ),
        };
    }

    /** @return ?string */
    private static function checkString(mixed $value, bool $allowsControlChars): ?string
    {
        if (!is_string($value)) {
            return 'This field must be a string.';
        }

        if (!$allowsControlChars && preg_match(self::CONTROL_PATTERN, $value) === 1) {
            return 'This field contains forbidden control characters.';
        }

        return null;
    }

    /**
     * Integer rule with the one documented lossless cast.
     *
     * @return array{0: ?string, 1: mixed}
     */
    private static function integerCast(mixed $value): array
    {
        if (is_int($value)) {
            return [null, $value];
        }

        if (is_string($value) && preg_match('/^[+-]?[0-9]+$/', $value) === 1) {
            $cast = (int) $value;

            // Lossless check: re-encoding the cast must reproduce the input.
            return ((string) $cast === $value) ? [null, $cast] : ['This field must be an integer.', $value];
        }

        return ['This field must be an integer.', $value];
    }

    /**
     * @return array{0: ?string, 1: mixed}
     */
    private static function numericCast(mixed $value): array
    {
        if (is_int($value) || is_float($value)) {
            return [null, $value];
        }

        if ($value === '') {
            return ['This field must be a number.', $value];
        }

        if (is_string($value) && is_numeric($value)) {
            $cast = $value + 0;

            if ((string) $cast === $value) {
                return [null, $cast]; // lossless digit-string → int
            }

            return [null, $cast]; // numeric text → numeric value ('007' → 7.0)
        }

        return ['This field must be a number.', $value];
    }

    /**
     * @return array{0: ?string, 1: mixed}
     */
    private static function booleanCast(mixed $value): array
    {
        if (is_bool($value)) {
            return [null, $value];
        }

        if (is_string($value) && in_array($value, ['true', 'false'], true)) {
            return [null, $value === 'true'];
        }

        return ['This field must be a boolean.', $value];
    }

    /**
     * Size bounds (`min:N` / `max:N`). Size semantics follow the value's
     * own type: numbers compare by value, strings by length (mb-aware),
     * arrays by element count. Other types are judged by the type rules,
     * so the bound skips them silently.
     */
    private static function sizeBound(string $bound, string $rule, mixed $value): ?string
    {
        $rawBound = substr($rule, 4);

        if ($rawBound === '' || !is_numeric($rawBound)) {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s] needs a numeric argument.', $rule),
            );
        }

        $size = self::sizeOf($value);

        if ($size === null) {
            return null;
        }

        $boundValue = $rawBound + 0.0;

        if (($bound === 'min' && $size < $boundValue)
            || ($bound === 'max' && $size > $boundValue)) {
            $kind = match (true) {
                is_string($value) => 'characters',
                is_array($value) => 'items',
                default => null,
            };

            if ($kind !== null) {
                return $bound === 'min'
                    ? sprintf('This field must have at least %s %s.', $rawBound, $kind)
                    : sprintf('This field must have at most %s %s.', $rawBound, $kind);
            }

            return $bound === 'min'
                ? sprintf('This field must be greater than or equal to %s.', $rawBound)
                : sprintf('This field must be less than or equal to %s.', $rawBound);
        }

        return null;
    }

    /**
     * `between:min,max` — both size bounds at once, same semantics.
     */
    private static function betweenRule(string $rule, mixed $value): ?string
    {
        $bounds = substr($rule, 8);

        if (preg_match('/^(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)$/', $bounds, $m) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s] needs `between:min,max` numeric bounds.', $rule),
            );
        }

        $size = self::sizeOf($value);

        if ($size === null) {
            return null;
        }

        if ($size < (float) $m[1] || $size > (float) $m[2]) {
            return sprintf('This field must be between %s and %s.', $m[1], $m[2]);
        }

        return null;
    }

    /**
     * The "size" of a value for bound comparisons: numeric value, string
     * length (mb-aware), array element count; null for anything else.
     */
    private static function sizeOf(mixed $value): ?float
    {
        return match (true) {
            is_int($value), is_float($value) => $value + 0.0,
            is_string($value) => (float) mb_strlen($value),
            is_array($value) => (float) count($value),
            $value instanceof \Sahil\Http\UploadedFile => $value->size() !== null
                ? (float) $value->size()
                : null,
            default => null,
        };
    }

    /**
     * `confirmed` — the value must equal `{field}_confirmation`;
     * `confirmed:other` compares against an explicitly named field instead.
     *
     * @param array<string, mixed> $data
     */
    private function confirmedRule(string $rule, string $path, mixed $value, array $data): ?string
    {
        $other = str_starts_with($rule, 'confirmed:')
            ? substr($rule, 10)
            : $path.'_confirmation';

        if ($other === '') {
            throw new InvalidArgumentException('Validation rule [confirmed:] needs a field name.');
        }

        [$exists, $otherValue] = self::pathLookup($data, $other);

        return $exists && $otherValue === $value
            ? null
            : 'The field confirmation does not match.';
    }

    /**
     * `same:field` / `different:field` — strict comparison with another
     * field's value; a missing counterpart fails both ways.
     *
     * @param array<string, mixed> $data
     */
    private function comparisonRule(string $kind, string $other, mixed $value, array $data): ?string
    {
        if ($other === '') {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s:] needs a field name.', $kind),
            );
        }

        [$exists, $otherValue] = self::pathLookup($data, $other);
        $identical = $exists && $otherValue === $value;

        if ($kind === 'same') {
            return $identical ? null : 'This field must match the other field.';
        }

        return $identical ? 'This field must be different from the other field.' : null;
    }

    /**
     * `accepted` — the value a checkbox/agreement submits when ticked.
     */
    private static function accepted(mixed $value): ?string
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true)
            ? null
            : 'This field must be accepted.';
    }

    /**
     * `date` — any string `strtotime` can interpret, or a DateTimeInterface
     * instance carried through (e.g. from JSON-normalised input).
     */
    private static function dateRule(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return null;
        }

        return is_string($value) && $value !== '' && strtotime($value) !== false
            ? null
            : 'This field must be a valid date.';
    }

    /**
     * `before:x` / `after:x` — strict chronological comparison; the argument
     * accepts any `strtotime`-able expression ('2025-01-01', 'today', 'now',
     * '+30 days'). Both sides must parse; an unparseable argument is a
     * developer error, an unparseable value fails `date` first anyway.
     */
    private static function dateComparison(string $kind, string $argument, mixed $value): ?string
    {
        if ($argument === '') {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s:] needs a date expression.', $kind),
            );
        }

        $threshold = strtotime($argument);

        if ($threshold === false) {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s:%s] is not a recognisable date expression.', $kind, $argument),
            );
        }

        if ($value instanceof \DateTimeInterface) {
            $timestamp = $value->getTimestamp();
        } elseif (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                return null; // `date` owns the malformed-input message
            }
        } else {
            return null;
        }

        $passes = match ($kind) {
            'before' => $timestamp < $threshold,
            'after' => $timestamp > $threshold,
            'before_or_equal' => $timestamp <= $threshold,
            'after_or_equal' => $timestamp >= $threshold,
            default => throw new InvalidArgumentException('Unknown date comparison ['.$kind.'].'),
        };

        $label = str_replace('_', ' ', $kind);

        return $passes
            ? null
            : sprintf('This field must be %s %s.', $label, $argument);
    }

    /** @return ?string */
    private static function lengthRule(string $rule, mixed $value): ?string
    {
        if (!is_string($value) && !is_array($value)) {
            return null;
        }

        $bounds = substr($rule, 7);

        if ($bounds === '' || preg_match('/^(\d+)(?:,(\d+))?$/', $bounds) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s] needs `length:min` or `length:min,max`.', $rule),
            );
        }

        [$min, $max] = array_pad(explode(',', $bounds), 2, null);
        $length = is_string($value) ? mb_strlen($value) : count($value);

        if ($min !== null && $length < (int) $min) {
            return sprintf('This field must have at least %d characters.', (int) $min);
        }

        if ($max !== null && $max !== '' && $length > (int) $max) {
            return sprintf('This field must have at most %d characters.', (int) $max);
        }

        return null;
    }

    /**
     * `size:N` — exact size, using the same per-type semantics as
     * min/max (numeric value, string length, array count, file bytes).
     */
    private static function sizeRule(string $rule, mixed $value): ?string
    {
        $raw = substr($rule, 5);

        if ($raw === '' || !is_numeric($raw)) {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s] needs a numeric argument.', $rule),
            );
        }

        $size = self::sizeOf($value);

        if ($size === null) {
            return null;
        }

        return $size === $raw + 0.0
            ? null
            : sprintf('This field must have a size of %s.', $raw);
    }

    /** `digits:N` — a string of exactly N decimal digits. */
    private static function digitsRule(string $rule, mixed $value): ?string
    {
        $raw = substr($rule, 7);

        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s] needs a digit count.', $rule),
            );
        }

        if (!is_string($value) && !is_int($value)) {
            return 'This field must be a '.$raw.'-digit number.';
        }

        return preg_match('/^\d{'.(int) $raw.'}$/', (string) $value) === 1
            ? null
            : 'This field must be a '.$raw.'-digit number.';
    }

    /** `digits_between:min,max` — digit-string length bounds. */
    private static function digitsBetweenRule(string $rule, mixed $value): ?string
    {
        $bounds = substr($rule, 15);

        if (preg_match('/^(\d+),(\d+)$/', $bounds, $m) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s] needs `digits_between:min,max`.', $rule),
            );
        }

        if (!is_string($value) && !is_int($value)) {
            return sprintf('This field must be between %s and %s digits.', $m[1], $m[2]);
        }

        $text = (string) $value;
        $length = preg_match('/^\d+$/', $text) === 1 ? strlen($text) : -1;

        return $length >= (int) $m[1] && $length <= (int) $m[2]
            ? null
            : sprintf('This field must be between %s and %s digits.', $m[1], $m[2]);
    }

    /** `json` — the value must be a valid JSON document. */
    private static function jsonRule(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return 'This field must be valid JSON.';
        }

        try {
            json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'This field must be valid JSON.';
        }

        return null;
    }

    /**
     * `date_format:fmt` — strict parsing via
     * {@see \DateTimeImmutable::createFromFormat()}; trailing data or any
     * parse warning fails the rule.
     */
    private static function dateFormatRule(string $rule, mixed $value): ?string
    {
        $format = substr($rule, 12);

        if ($format === '') {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s] needs a date format.', $rule),
            );
        }

        if (!is_string($value) || $value === '') {
            return 'This field must match the date format '.$format.'.';
        }

        $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $value);

        return $parsed === false || \DateTimeImmutable::getLastErrors() !== false
            ? 'This field must match the date format '.$format.'.'
            : null;
    }

    /** @return ?string */
    private static function membership(bool $inclusion, string $rule, mixed $value): ?string
    {
        $prefix = $inclusion ? 3 : 6;
        $rawList = substr($rule, $prefix);

        if ($rawList === '') {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s] needs at least one value.', $rule),
            );
        }

        $allowed = array_map(trim(...), explode(',', $rawList));

        $asText = is_string($value) ? $value : (is_scalar($value) ? (string) $value : null);

        if ($asText === null) {
            return sprintf('Allowed values: %s.', implode(', ', $allowed));
        }

        $found = in_array($asText, $allowed, true);

        if ($inclusion && !$found) {
            return sprintf('This field must be one of: %s.', implode(', ', $allowed));
        }

        if (!$inclusion && $found) {
            return sprintf('This field must not be one of: %s.', implode(', ', $allowed));
        }

        return null;
    }

    /** @return ?string */
    private static function pattern(string $rule, string $path, mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $pattern = substr($rule, 6);

        if (strlen($pattern) < 2 || $pattern[0] !== $pattern[-1]) {
            throw new InvalidArgumentException(
                sprintf('regex rule on [%s] needs a delimited PCRE pattern, got [%s].', $path, $pattern),
            );
        }

        if (in_array('e', self::modifiers($pattern), true)) {
            throw new InvalidArgumentException(
                sprintf('regex rule on [%s] uses the forbidden PCRE "e" modifier.', $path),
            );
        }

        return preg_match($pattern, $value) === 1 ? null : 'This field format is not allowed.';
    }

    /** @return list<string> */
    private static function modifiers(string $pattern): array
    {
        $closing = strrpos($pattern, $pattern[0]);

        if ($closing === false || $closing < 1) {
            return [];
        }

        $modifiers = substr($pattern, $closing + 1);

        return $modifiers === '' ? [] : str_split($modifiers);
    }

    /**
     * Dotted lookup. Returns [exists, value]; a scalar parent makes the
     * nested field absent on principle.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: bool, 1: mixed}
     */
    /**
     * File rules — `file`, `image`, `mimes:a,b`, `mimetypes:a/b,c/d`,
     * `extensions:a,b`. Content is proven by the file itself (finfo on
     * `image`/`mimetypes`, filename extension for `mimes`/`extensions`),
     * never by a client-supplied Content-Type.
     *
     * @param list<string> $arguments
     */
    private static function fileRule(string $kind, array $arguments, mixed $value): ?string
    {
        if (!$value instanceof \Sahil\Http\UploadedFile) {
            return 'This field must be a file.';
        }

        if (!$value->isValid()) {
            return 'This field must be a valid uploaded file.';
        }

        return match ($kind) {
            'file' => null,
            'image' => self::isImageMime($value->mime())
                ? null
                : 'This field must be an image.',
            'extensions' => in_array(strtolower($value->extension() ?? ''), array_map(strtolower(...), $arguments), true)
                ? null
                : 'This field must have one of these extensions: '.implode(', ', $arguments).'.',
            'mimes' => self::mimeMatchesExtensions($value, $arguments)
                ? null
                : 'This field must be a file of type: '.implode(', ', $arguments).'.',
            'mimetypes' => in_array($value->mime(), $arguments, true)
                ? null
                : 'This field must be a file of type: '.implode(', ', $arguments).'.',
            default => null,
        };
    }

    private static function isImageMime(?string $mime): bool
    {
        return $mime !== null && str_starts_with($mime, 'image/');
    }

    /**
     * `mimes:` takes extensions (like `jpg,pdf`) and proves them against
     * BOTH the filename and the detected MIME — an executable renamed
     * `x.jpg` fails even though its extension matches.
     *
     * @param list<string> $extensions
     */
    private static function mimeMatchesExtensions(\Sahil\Http\UploadedFile $file, array $extensions): bool
    {
        $extension = strtolower($file->extension() ?? '');

        if (!in_array($extension, array_map(strtolower(...), $extensions), true)) {
            return false;
        }

        $mime = $file->mime();

        if ($mime === null) {
            return false;
        }

        // The extension must map to a KNOWN MIME family — an unrecognized
        // extension can never prove content, so it fails outright.
        $allowed = self::mimeByExtension($extension);

        return $allowed !== [] && in_array($mime, $allowed, true);
    }

    /** @return list<string> */
    private static function mimeByExtension(string $extension): array
    {
        return match ($extension) {
            'jpg', 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'bmp' => ['image/bmp', 'image/x-ms-bmp'],
            'svg' => ['image/svg+xml', 'text/xml', 'application/xml'],
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'csv' => ['text/plain', 'text/csv', 'application/csv'],
            'json' => ['application/json', 'text/plain'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            default => [],
        };
    }

    private static function pathLookup(array $data, string $path): array
    {
        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }

    /**
     * Message lookup: `field.rule` → `field` → bare `rule` →
     * `validation.*` catalog in an application context → default.
     *
     * Inside a configured application the framework's built-in messages are
     * localized through the translator, so default validation feedback
     * follows the active locale (Swahili out of the box). A Validator
     * constructed outside an application keeps the English defaults.
     *
     * @param string $rule the full rule descriptor, for argument extraction
     */
    private function message(string $path, string $ruleName, string $default, string $rule): string
    {
        $explicit = $this->messages[$path.'.'.$ruleName]
            ?? $this->messages[$path]
            ?? $this->messages[$ruleName]
            ?? null;

        if ($explicit !== null) {
            return $explicit;
        }

        return $this->translated($path, $ruleName, $default, $rule) ?? $default;
    }

    /**
     * Ask the application's translator for the rule's localized message.
     * Returns null when no application is configured or the key is absent,
     * leaving the English default in place.
     */
    private function translated(string $path, string $ruleName, string $default, string $rule): ?string
    {
        $application = \Sahil\Foundation\Application::peek();

        if ($application === null) {
            return null;
        }

        $container = $application->container();

        if (!$container->bound(\Sahil\I18n\TranslatorContract::class)) {
            return null;
        }

        try {
            /** @var \Sahil\I18n\TranslatorContract $translator */
            $translator = $container->make(\Sahil\I18n\TranslatorContract::class);
        } catch (\Throwable) {
            return null;
        }

        $key = 'validation.'.$this->messageKey($ruleName, $default);
        $translated = $translator->get($key, ['attribute' => $path] + $this->ruleParameters($rule));

        return $translated === $key ? null : $translated;
    }

    /**
     * Most rules map straight onto `validation.{rule}`; the few built-in
     * rules with more than one failure message are disambiguated by the
     * produced default (translation lookup only — the default stands when
     * no key matches).
     */
    private function messageKey(string $ruleName, string $default): string
    {
        return match (true) {
            $ruleName === 'string' && str_contains($default, 'control characters') => 'control_characters',
            $ruleName === 'length' && str_contains($default, 'at least') => 'length.min',
            $ruleName === 'length' => 'length.max',
            // `min`/`max` cover numbers, string lengths and array counts —
            // the English default's wording identifies which key applies.
            $ruleName === 'min' && str_contains($default, 'characters') => 'min.characters',
            $ruleName === 'min' && str_contains($default, 'items') => 'min.items',
            $ruleName === 'min' => 'min.numeric',
            $ruleName === 'max' && str_contains($default, 'characters') => 'max.characters',
            $ruleName === 'max' && str_contains($default, 'items') => 'max.items',
            $ruleName === 'max' => 'max.numeric',
            $ruleName === 'not_in' => 'notIn',
            default => $ruleName,
        };
    }

    /**
     * Rule arguments exposed to translated messages as `:name` placeholders.
     *
     * @return array<string, string>
     */
    private function ruleParameters(string $rule): array
    {
        if (str_starts_with($rule, 'min:')) {
            return ['min' => substr($rule, 4)];
        }

        if (str_starts_with($rule, 'max:')) {
            return ['max' => substr($rule, 4)];
        }

        if (str_starts_with($rule, 'length:')) {
            $bounds = array_pad(explode(',', substr($rule, 7), 2), 2, '');

            return array_filter(['min' => $bounds[0], 'max' => $bounds[1]], static fn (string $b): bool => $b !== '');
        }

        if (str_starts_with($rule, 'in:')) {
            return ['values' => substr($rule, 3)];
        }

        if (str_starts_with($rule, 'size:')) {
            return ['size' => substr($rule, 5)];
        }

        if (str_starts_with($rule, 'digits_between:')) {
            [$low, $high] = explode(',', substr($rule, 15), 2) + ['', ''];

            return ['min' => $low, 'max' => $high];
        }

        if (str_starts_with($rule, 'digits:')) {
            return ['digits' => substr($rule, 7)];
        }

        if (str_starts_with($rule, 'date_format:')) {
            return ['format' => substr($rule, 12)];
        }

        if (str_starts_with($rule, 'before_or_equal:')) {
            return ['date' => substr($rule, 17)];
        }

        if (str_starts_with($rule, 'after_or_equal:')) {
            return ['date' => substr($rule, 15)];
        }

        if (str_starts_with($rule, 'mimetypes:')) {
            return ['values' => substr($rule, 10)];
        }

        if (str_starts_with($rule, 'mimes:')) {
            return ['values' => substr($rule, 6)];
        }

        if (str_starts_with($rule, 'extensions:')) {
            return ['values' => substr($rule, 11)];
        }

        if (str_starts_with($rule, 'notIn:') || str_starts_with($rule, 'not_in:')) {
            return ['values' => substr($rule, 6)];
        }

        if (str_starts_with($rule, 'regex:')) {
            return ['pattern' => substr($rule, 6)];
        }

        if (str_starts_with($rule, 'between:')) {
            [$low, $high] = explode(',', substr($rule, 8), 2) + ['', ''];

            return ['min' => $low, 'max' => $high];
        }

        if (str_starts_with($rule, 'same:')) {
            return ['other' => substr($rule, 5)];
        }

        if (str_starts_with($rule, 'different:')) {
            return ['other' => substr($rule, 10)];
        }

        if (str_starts_with($rule, 'confirmed:')) {
            return ['other' => substr($rule, 10)];
        }

        if (str_starts_with($rule, 'before:')) {
            return ['date' => substr($rule, 7)];
        }

        if (str_starts_with($rule, 'after:')) {
            return ['date' => substr($rule, 6)];
        }

        if ($rule === 'password') {
            return ['min' => (string) Password::DEFAULT_MIN];
        }

        if (str_starts_with($rule, 'password:')) {
            return ['min' => substr($rule, 9)];
        }

        return [];
    }
}
