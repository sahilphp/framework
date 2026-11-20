<?php

declare(strict_types=1);

namespace Sahil\Tests\Identification;

use PHPUnit\Framework\TestCase;
use Sahil\Identification\FrameworkIdentity;

/**
 * Validates the Wappalyzer contribution fingerprint
 * (`fingerprint/wappalyzer/sahilphp.json`) against the vendored upstream
 * WebAppAnalyzer schema, and checks that the detection patterns it
 * declares match the fingerprint the runtime actually emits.
 *
 * The validator implements the JSON-Schema subset the upstream schema uses
 * (type, required, properties, additionalProperties, patternProperties,
 * pattern, items, minItems, oneOf, $ref → $defs) — enough to fail loudly
 * when the entry drifts from the published contract.
 */
final class WappalyzerFingerprintTest extends TestCase
{
    private const DIR = __DIR__.'/../../fingerprint/wappalyzer';

    /** @var array<string, mixed> */
    private array $schema;

    /** @var array<string, mixed> */
    private array $entry;

    protected function setUp(): void
    {
        $this->schema = self::json(self::DIR.'/schema.json');
        $this->entry = self::json(self::DIR.'/sahilphp.json');
    }

    public function test_entry_satisfies_the_upstream_schema(): void
    {
        $violations = self::validate($this->entry, $this->schema, '$');

        self::assertSame([], $violations);
    }

    public function test_single_technology_named_sahilphp(): void
    {
        self::assertSame(['SahilPHP'], array_keys($this->entry));
    }

    public function test_categories_exist_in_the_upstream_catalogue(): void
    {
        $categories = self::json(self::DIR.'/categories.json');

        foreach ($this->entry['SahilPHP']['cats'] as $cat) {
            self::assertArrayHasKey((string) $cat, $categories, "unknown category id {$cat}");
        }

        self::assertSame('Web frameworks', $categories['18']['name']);
    }

    public function test_icon_file_exists_and_matches_the_declared_name(): void
    {
        $icon = $this->entry['SahilPHP']['icon'];

        self::assertFileExists(self::DIR.'/icons/'.$icon);
        self::assertMatchesRegularExpression('/\.(png|jpe?g|svg)$/i', $icon);
    }

    public function test_declared_patterns_compile_and_match_the_runtime_fingerprint(): void
    {
        $technology = $this->entry['SahilPHP'];

        $generator = $technology['meta']['generator'];
        $regex = self::patternRegex($generator);

        self::assertNotFalse(@preg_match('~'.$regex.'~', ''), "invalid meta.generator regex: {$regex}");

        // The pattern must match both forms the runtime emits and extract
        // the version group Wappalyzer reads through \;version:\1.
        self::assertSame(1, preg_match('~'.$regex.'~', 'SahilPHP', $bare));
        self::assertSame('', $bare[1] ?? '');
        self::assertSame(1, preg_match('~'.$regex.'~', 'SahilPHP 1.3.0', $versioned));
        self::assertSame('1.3.0', $versioned[1]);
        self::assertSame(0, preg_match('~'.$regex.'~', 'WordPress'));

        $header = self::patternRegex($technology['headers']['X-Powered-By']);

        self::assertNotFalse(@preg_match('~'.$header.'~', ''));
        self::assertSame(1, preg_match('~'.$header.'~', FrameworkIdentity::poweredBy()));
        self::assertSame(0, preg_match('~'.$header.'~', 'PHP/8.4'));

        self::assertSame('SahilPHP', FrameworkIdentity::NAME);
        self::assertSame('sahil_session', array_key_first($technology['cookies']));
    }

    /**
     * Strip Wappalyzer `\;flag` suffixes (`\;confidence:`, `\;version:`)
     * from a declared pattern, returning the regular expression part.
     */
    private static function patternRegex(string $pattern): string
    {
        return preg_replace('/\\\\;.*/', '', $pattern) ?? $pattern;
    }

    /** @return array<string, mixed> */
    private static function json(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        self::assertIsArray($decoded, sprintf('%s is not valid JSON', $path));

        return $decoded;
    }

    // ------------------------------------------------------------------
    // Minimal JSON-Schema validator (subset used by the upstream schema)
    // ------------------------------------------------------------------

    /**
     * @return list<string> violation messages
     */
    private static function validate(mixed $instance, array $schema, string $path): array
    {
        if (isset($schema['$ref'])) {
            $target = self::resolveRef($schema['$ref']);

            return self::validate($instance, $target, $path);
        }

        if (isset($schema['oneOf'])) {
            $matches = 0;

            foreach ($schema['oneOf'] as $candidate) {
                if (self::validate($instance, $candidate, $path) === []) {
                    $matches++;
                }
            }

            return $matches === 1 ? [] : ["{$path}: expected exactly one oneOf schema to match, {$matches} did"];
        }

        $violations = [];

        if (isset($schema['type'])) {
            $ok = match ($schema['type']) {
                'object' => is_array($instance) && ($instance === [] || !array_is_list($instance)),
                'array' => is_array($instance) && array_is_list($instance),
                'string' => is_string($instance),
                'number', 'integer' => is_int($instance) || is_float($instance),
                'boolean' => is_bool($instance),
                default => true,
            };

            if (!$ok) {
                return [sprintf('%s: expected type %s, got %s', $path, $schema['type'], get_debug_type($instance))];
            }
        }

        foreach (($schema['required'] ?? []) as $key) {
            if (!is_array($instance) || !array_key_exists($key, $instance)) {
                $violations[] = "{$path}: missing required property {$key}";
            }
        }

        if (isset($schema['minItems']) && is_array($instance) && count($instance) < $schema['minItems']) {
            $violations[] = "{$path}: expected at least {$schema['minItems']} items";
        }

        if (isset($schema['items']) && is_array($instance)) {
            foreach ($instance as $index => $item) {
                array_push($violations, ...self::validate($item, $schema['items'], "{$path}[{$index}]"));
            }
        }

        if (isset($schema['pattern']) && is_string($instance)
            && @preg_match('~'.$schema['pattern'].'~u', $instance) !== 1) {
            $violations[] = "{$path}: string does not match pattern {$schema['pattern']}";
        }

        if (is_array($instance)) {
            foreach ($instance as $key => $value) {
                $matched = false;

                if (isset($schema['properties'][$key])) {
                    array_push($violations, ...self::validate($value, $schema['properties'][$key], "{$path}.{$key}"));
                    $matched = true;
                }

                foreach (($schema['patternProperties'] ?? []) as $pattern => $sub) {
                    if (@preg_match('~'.$pattern.'~', (string) $key) === 1) {
                        array_push($violations, ...self::validate($value, $sub, "{$path}.{$key}"));
                        $matched = true;
                    }
                }

                if (!$matched) {
                    if (($schema['additionalProperties'] ?? true) === false) {
                        $violations[] = "{$path}: unknown property {$key}";
                    } elseif (is_array($schema['additionalProperties'] ?? null)) {
                        array_push($violations, ...self::validate($value, $schema['additionalProperties'], "{$path}.{$key}"));
                    }
                }
            }
        }

        return $violations;
    }

    /** @return array<string, mixed> */
    private static function resolveRef(string $ref): array
    {
        $schema = self::json(self::DIR.'/schema.json');
        $pointer = str_starts_with($ref, '#/') ? substr($ref, 2) : $ref;
        $node = $schema;

        foreach (explode('/', $pointer) as $segment) {
            $node = $node[$segment] ?? [];
        }

        self::assertIsArray($node, "unresolvable \$ref {$ref}");

        return $node;
    }
}
