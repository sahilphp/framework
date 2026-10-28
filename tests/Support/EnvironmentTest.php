<?php

declare(strict_types=1);

namespace Sahil\Tests\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Support\Environment;

final class EnvironmentTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/example.env';

    public function test_loads_and_unquotes_values_from_fixture(): void
    {
        $environment = new Environment();
        $environment->load(self::FIXTURE);

        self::assertSame('SahilPHP Demo', $environment->get('APP_NAME'));
        self::assertSame('localhost', $environment->get('DB_HOST'));
        self::assertTrue($environment->get('APP_DEBUG'));
        self::assertSame('production', $environment->get('APP_ENV'));
    }

    public function test_single_quoted_values_are_kept_verbatim(): void
    {
        $environment = new Environment();
        $environment->load(self::FIXTURE);

        self::assertSame('one two three', $environment->get('DB_CONFIG'));
        self::assertSame('  spaced secret  ', $environment->get('DB_SECRET'));
    }

    public function test_double_quoted_escapes_are_expanded(): void
    {
        $environment = new Environment();
        $environment->load(self::FIXTURE);

        self::assertSame("line one\nline two", $environment->get('QUOTED_NEWLINE'));
    }

    public function test_hash_inside_quotes_is_preserved_but_trailing_comment_stripped(): void
    {
        $environment = new Environment();
        $environment->load(self::FIXTURE);

        self::assertSame('pass#word', $environment->get('BASHED_SECRET'));
        self::assertSame('kept', $environment->get('UNQUOTED_COMMENT'));
    }

    public function test_special_literals_are_converted(): void
    {
        $environment = new Environment();
        $environment->load(self::FIXTURE);

        self::assertNull($environment->get('SOME_NULL'));
        self::assertSame('', $environment->get('SOME_EMPTY'));
        // Numbers deliberately stay strings; the caller (e.g. the config
        // layer) decides how to cast them.
        self::assertSame('123', $environment->get('PLAIN_NUMBER'));
    }

    public function test_unset_keys_return_the_supplied_default(): void
    {
        $environment = new Environment();

        self::assertNull($environment->get('NOT_SET'));
        self::assertSame('fallback', $environment->get('NOT_SET', 'fallback'));
    }

    public function test_has_is_false_for_empty_and_null_values(): void
    {
        $environment = new Environment();
        $environment->load(self::FIXTURE);

        self::assertTrue($environment->has('APP_NAME'));
        self::assertFalse($environment->has('SOME_NULL'));
        self::assertFalse($environment->has('SOME_EMPTY'));
        self::assertFalse($environment->has('TOTALLY_MISSING'));
    }

    public function test_os_variables_seed_the_map(): void
    {
        $environment = new Environment(['FROM_OS' => 'yes']);

        self::assertSame('yes', $environment->get('FROM_OS'));
    }

    public function test_malformed_lines_fail_loudly(): void
    {
        $path = sys_get_temp_dir().'/sahil-malformed-'.uniqid('', true).'.env';
        file_put_contents($path, "GOOD=value\nNO_EQUALS_SIGN_HERE\n");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('NO_EQUALS_SIGN_HERE');
            (new Environment())->load($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_non_string_keys_in_initial_map(): void
    {
        $this->expectException(RuntimeException::class);

        new Environment([5 => 'value']);
    }

    public function test_keys_diagnostics_listing(): void
    {
        $environment = new Environment();
        $environment->load(self::FIXTURE);

        $keys = $environment->keys();

        self::assertContains('APP_NAME', $keys);
        self::assertContains('PLAIN_NUMBER', $keys);
    }
}
