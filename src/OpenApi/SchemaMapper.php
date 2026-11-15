<?php

declare(strict_types=1);

namespace Sahil\OpenApi;

use Sahil\Validation\RuleContract;

/**
 * Maps SahilPHP validation rules to JSON Schema (OpenAPI 3.1 dialect).
 *
 * Only rules with unambiguous semantics map — anything unsupported or
 * custom produces an `x-validation-rule` extension note plus a
 * diagnostic, never a false claim.
 */
final class SchemaMapper
{
    /** Rule → static schema fragment. */
    private const TYPES = [
        'string' => ['type' => 'string'],
        'integer' => ['type' => 'integer'],
        'numeric' => ['type' => 'number'],
        'boolean' => ['type' => 'boolean'],
        'array' => ['type' => 'array'],
        'email' => ['type' => 'string', 'format' => 'email'],
        'url' => ['type' => 'string', 'format' => 'uri'],
        'uuid' => ['type' => 'string', 'format' => 'uuid'],
        'ip' => ['type' => 'string', 'format' => 'ip'],
        'json' => ['type' => 'string', 'format' => 'json'],
        'alpha' => ['type' => 'string', 'pattern' => '^\p{L}+$'],
        'alpha_num' => ['type' => 'string', 'pattern' => '^[\p{L}\p{N}]+$'],
        'alpha_dash' => ['type' => 'string', 'pattern' => '^[\p{L}\p{N}_-]+$'],
        'date' => ['type' => 'string', 'format' => 'date-time'],
        'file' => ['type' => 'string', 'format' => 'binary'],
        'image' => ['type' => 'string', 'format' => 'binary', 'description' => 'Image upload'],
    ];

    /**
     * Field rules (string `required|email` or array form) →
     * `['schema' => [...], 'required' => bool]`.
     *
     * @param string|list<string|RuleContract|callable> $rules
     */
    public static function field(string|array $rules, ?callable $diagnose = null): array
    {
        $schema = [];
        $required = false;
        $nullable = false;

        foreach (is_string($rules) ? self::splitRules($rules) : $rules as $rule) {
            if (!is_string($rule)) {
                $schema['x-validation-rule'] = is_object($rule) ? $rule::class : 'closure';
                $diagnose !== null && $diagnose('warning', sprintf(
                    'Custom rule [%s] cannot be mapped to a schema; documented as x-validation-rule.',
                    is_object($rule) ? $rule::class : 'closure',
                ));

                continue;
            }

            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
            $name = strtolower($name);

            switch (true) {
                case $name === 'required' || str_starts_with($name, 'required_'):
                    $required = true;
                    break;
                case $name === 'nullable':
                    $nullable = true;
                    break;
                case isset(self::TYPES[$name]):
                    $schema = [...$schema, ...self::TYPES[$name]];
                    break;
                case $name === 'min':
                    $schema += self::sizeConstraint($schema, 'min', $param);
                    break;
                case $name === 'max':
                    $schema += self::sizeConstraint($schema, 'max', $param);
                    break;
                case $name === 'size':
                    $schema += self::sizeConstraint($schema, 'min', $param);
                    $schema += self::sizeConstraint($schema, 'max', $param);
                    break;
                case $name === 'between':
                    [$lo, $hi] = array_pad(explode(',', (string) $param), 2, null);
                    $schema += self::sizeConstraint($schema, 'min', $lo);
                    $schema += self::sizeConstraint($schema, 'max', $hi);
                    break;
                case $name === 'digits':
                    $schema += ['type' => 'string', 'pattern' => '^\d{'.(int) $param.'}$'];
                    break;
                case $name === 'digits_between':
                    [$lo, $hi] = array_pad(explode(',', (string) $param), 2, null);
                    $schema += ['type' => 'string', 'pattern' => '^\d{'.(int) $lo.','.(int) $hi.'}$'];
                    break;
                case $name === 'in':
                    $schema += ['enum' => array_map(
                        static fn (string $v) => is_numeric($v) ? $v + 0 : $v,
                        explode(',', (string) $param),
                    )];
                    break;
                case $name === 'not_in':
                    // Exclusion — JSON Schema `not`, never a positive enum.
                    $schema += ['not' => ['enum' => array_map(
                        static fn (string $v) => is_numeric($v) ? $v + 0 : $v,
                        explode(',', (string) $param),
                    )]];
                    break;
                case $name === 'regex':
                    $schema += ['type' => 'string', 'pattern' => (string) $param];
                    break;
                case $name === 'date_format':
                    $schema += ['type' => 'string', 'x-date-format' => $param];
                    break;
                case $name === 'mimes':
                    $schema += ['x-mime-extensions' => explode(',', (string) $param)];
                    break;
                case $name === 'accepted':
                    $schema += ['type' => 'boolean'];
                    break;
                case $name === 'confirmed' || $name === 'same' || $name === 'different':
                    $schema['x-validation-rule'] = $name;
                    break;
                case $name === 'filled' || $name === 'present' || $name === 'missing'
                    || $name === 'sometimes':
                    $schema['x-validation-rule'] = $name;
                    $diagnose !== null && $diagnose('warning', "Rule [$name] has no schema equivalent; noted as extension.");
                    break;
                default:
                    $schema['x-validation-rule'] = $name;
                    $diagnose !== null && $diagnose('warning', "Unsupported rule [$name] documented as x-validation-rule.");
            }
        }

        if ($nullable) {
            if (isset($schema['type']) && !is_array($schema['type'])) {
                $schema['type'] = [$schema['type'], 'null'];
            } elseif (!isset($schema['type'])) {
                $schema['type'] = ['string', 'null'];
            }
        }

        return ['schema' => $schema === [] ? ['type' => 'string'] : $schema, 'required' => $required];
    }

    /**
     * Same escaped-pipe-aware splitting as `Validator::splitRules` —
     * `regex:/a\|b/` must not shatter on the literal pipe.
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
                $slashes = 0;
                for ($j = $i - 1; $j >= 0 && $current !== '' && $current[strlen($current) - 1 - $slashes] === '\\'; $slashes++) {
                }

                if ($slashes % 2 === 1) {
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

    /** min/max mean string length for strings, bounds for numbers, items for arrays. */
    private static function sizeConstraint(array $schema, string $which, mixed $param): array
    {
        $n = (int) $param;
        $type = $schema['type'] ?? 'string';

        return match (true) {
            $type === 'integer', $type === 'number' => [$which === 'min' ? 'minimum' : 'maximum' => $n],
            $type === 'array' => [$which === 'min' ? 'minItems' : 'maxItems' => $n],
            $type === 'string' && (($schema['format'] ?? null) === 'binary')
                => ['x-max-file-bytes' => $n],
            default => [$which === 'min' ? 'minLength' : 'maxLength' => $n],
        };
    }

    /**
     * `['field' => rules, ...]` → `{type:object, properties, required}`.
     */
    public static function object(array $fields, ?callable $diagnose = null): array
    {
        $properties = [];
        $required = [];

        foreach ($fields as $field => $rules) {
            $mapped = self::field($rules, $diagnose);
            $properties[$field] = $mapped['schema'];

            if ($mapped['required']) {
                $required[] = $field;
            }
        }

        return array_filter([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required === [] ? null : $required,
        ]);
    }
}
