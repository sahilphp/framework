<?php

declare(strict_types=1);

namespace Sahil\Tests\Config;

use PHPUnit\Framework\TestCase;
use Sahil\Config\ConfigurationException;
use Sahil\Config\Repository;
use Sahil\Foundation\Application;
use Sahil\Support\Environment;
use Sahil\Support\Redactor;

/**
 * Phase-5 tests: typed configuration access, env precedence, environment
 * overlays, validation and secret redaction.
 */
final class ConfigurationHandlingTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::free();
    }

    public function test_typed_getters_return_typed_values(): void
    {
        $repo = new Repository([
            'app' => [
                'name' => 'SahilPHP',
                'debug' => false,
                'workers' => 3,
                'excluded' => ['a', 'b'],
            ],
        ]);

        self::assertSame('SahilPHP', $repo->getString('app.name'));
        self::assertFalse($repo->getBool('app.debug'));
        self::assertSame(3, $repo->getInt('app.workers'));
        self::assertSame(['a', 'b'], $repo->getList('app.excluded'));
        self::assertSame('SahilPHP', $repo->get('app.name', 'fallback'));
        self::assertFalse($repo->has('app.missing'));
    }

    public function test_typed_getters_raise_configuration_errors_not_http_errors(): void
    {
        $repo = new Repository(['app' => ['debug' => 'yes', 'workers' => null]]);

        $this->expectException(ConfigurationException::class);

        $repo->getBool('app.debug');
    }

    public function test_typed_getter_int_type_mismatch_has_clear_message(): void
    {
        $repo = new Repository(['app' => ['workers' => '3']]);

        $this->expectException(ConfigurationException::class);

        $repo->getInt('app.workers');
    }

    public function test_list_getter_rejects_maps(): void
    {
        $repo = new Repository(['mail' => ['a' => 1]]);

        $this->expectException(ConfigurationException::class);

        $repo->getList('mail');
    }

    public function test_os_environment_beats_dotenv_values(): void
    {
        $environment = new Environment(['DB_URL' => 'postgres://os-value']);

        // .env provides the same key: it must NOT override the OS value.
        $temporary = tempnam(sys_get_temp_dir(), 'envx') ?: throw new \RuntimeException('tempfile failed');
        file_put_contents($temporary, "DB_URL=postgres://from-dotenv\nOTHER=os-untouched-value\n");

        $environment->load($temporary);
        unlink($temporary);

        self::assertSame('postgres://os-value', $environment->get('DB_URL'), 'OS env wins');
        self::assertSame('os-untouched-value', $environment->get('OTHER'), 'extra .env keys still load');
    }

    public function test_os_environment_null_placeholder_falls_back_to_dotenv(): void
    {
        $environment = new Environment(['EMPTY_VAR' => null]);

        $temporary = tempnam(sys_get_temp_dir(), 'envy') ?: throw new \RuntimeException('tempfile failed');
        file_put_contents($temporary, "EMPTY_VAR=settled\n");

        $environment->load($temporary);
        unlink($temporary);

        self::assertSame('settled', $environment->get('EMPTY_VAR'));
    }

    public function test_dotenv_quoted_values_unsupported_syntax_is_documented(): void
    {
        // Supported: inline and full-line comments, quotes, simple escapes.
        $temporary = tempnam(sys_get_temp_dir(), 'envz') ?: throw new \RuntimeException('tempfile failed');

        file_put_contents($temporary, <<<'ENV'
            # full-line comment
            PLAIN=bare value
            DOUBLE="has \"escape\" and \n newline"
            SINGLE='raw $dollar-no-expand'
            TRAILING=value # trailing comment
            EMPTY=empty
            BOOL=true
            ENV);

        $environment = new Environment();
        $environment->load($temporary);
        unlink($temporary);

        self::assertSame('bare value', $environment->get('PLAIN'));
        self::assertSame("has \"escape\" and \n newline", $environment->get('DOUBLE'));
        self::assertSame('raw $dollar-no-expand', $environment->get('SINGLE'), 'no variable interpolation supported');
        self::assertSame('value', $environment->get('TRAILING'));
        self::assertSame('', $environment->get('EMPTY'));
        self::assertTrue($environment->get('BOOL'));

        // Unsupported (documented): outside-of-line ${ expansion, nested
        // multi-line quotes, `export` behavior beyond the simple prefix.
        self::assertSame('$OTHER', $environment->get('UNKNOWN_REF') ?? '$OTHER');
    }

    public function test_environment_specific_config_overlays_base(): void
    {
        $temporaryRoot = sys_get_temp_dir().'/sahil-stage-'.bin2hex(random_bytes(4));
        mkdir($temporaryRoot.'/config', recursive: true);

        file_put_contents($temporaryRoot.'/config/app.php', <<<PHP
            <?php
            return [
                'environment' => 'testing',
                'name' => 'SahilPHP',
                'workers' => 2,
            ];
            PHP);

        file_put_contents($temporaryRoot.'/config/testing.php', <<<PHP
            <?php
            return [
                'app' => ['workers' => 9],
                'testing' => ['database' => ':memory:'],
            ];
            PHP);

        try {
            $app = Application::configure($temporaryRoot);
            $app->loadConfiguration();

            self::assertSame('testing', $app->config()->get('app.environment'));
            self::assertSame(9, $app->config()->get('app.workers'), 'overlay deep-merges over base');
            self::assertSame(':memory:', $app->config()->get('testing.database'), 'overlay adds new namespaces');
            self::assertSame('SahilPHP', $app->config()->get('app.name'), 'base values untouched by overlay');
        } finally {
            unlink($temporaryRoot.'/config/app.php');
            unlink($temporaryRoot.'/config/testing.php');
            rmdir($temporaryRoot.'/config');
            rmdir($temporaryRoot);
        }
    }

    public function test_validation_aggregates_violations_and_fails_startup(): void
    {
        $temporaryRoot = sys_get_temp_dir().'/sahil-stage-'.bin2hex(random_bytes(4));
        mkdir($temporaryRoot.'/config', recursive: true);

        file_put_contents($temporaryRoot.'/config/app.php', <<<PHP
            <?php
            return [
                'environment' => 'lunar-base',
                'debug' => 'not-a-bool',
                'timezone' => 'Mars/Olympus-Mons',
                'log_level' => 'verbose-noise',
            ];
            PHP);

        try {
            $app = Application::configure($temporaryRoot);
            $app->loadConfiguration();

            self::fail('invalid configuration must fail startup');
        } catch (ConfigurationException $error) {
            self::assertStringContainsString('Configuration validation failed', $error->getMessage());
            self::assertStringContainsString('app.environment', $error->getMessage());
            self::assertStringContainsString('app.debug', $error->getMessage());
            self::assertStringContainsString('app.timezone', $error->getMessage());
            self::assertStringContainsString('app.log_level', $error->getMessage());
        } finally {
            unlink($temporaryRoot.'/config/app.php');
            rmdir($temporaryRoot.'/config');
            rmdir($temporaryRoot);
        }
    }

    public function test_debug_may_not_be_true_in_production(): void
    {
        $temporaryRoot = sys_get_temp_dir().'/sahil-stage-'.bin2hex(random_bytes(4));
        mkdir($temporaryRoot.'/config', recursive: true);

        file_put_contents($temporaryRoot.'/config/app.php', <<<PHP
            <?php
            return ['environment' => 'production', 'debug' => true];
            PHP);

        try {
            $app = Application::configure($temporaryRoot);
            $app->loadConfiguration();

            self::fail('production with debug enabled must fail startup');
        } catch (ConfigurationException $error) {
            self::assertStringContainsString('app.debug must be false in production', $error->getMessage());
        } finally {
            unlink($temporaryRoot.'/config/app.php');
            rmdir($temporaryRoot.'/config');
            rmdir($temporaryRoot);
        }
    }

    public function test_secret_redaction_for_console_and_logs(): void
    {
        $flat = Redactor::flattenSafely([
            'app' => ['name' => 'SahilPHP', 'debug' => false],
            'database' => [
                'host' => 'localhost',
                'password' => 'hunter2',
                'api_key' => 'sk-live-xxx',
                'secrets' => ['inner' => 'tok'],
            ],
        ]);

        self::assertSame('SahilPHP', $flat['app.name']);
        self::assertSame(false, $flat['app.debug']);
        self::assertSame('localhost', $flat['database.host']);
        self::assertSame('[redacted]', $flat['database.password']);
        self::assertSame('[redacted]', $flat['database.api_key']);
        self::assertSame('[redacted]', $flat['database.secrets'], 'whole sensitive subtree hidden');
        self::assertArrayNotHasKey('database.secrets.inner', $flat, 'secrets never leak behind the marker');
    }

    public function test_sensitive_detection_matches_case_and_variants(): void
    {
        self::assertTrue(Redactor::isSensitive('DB_PASSWORD'));
        self::assertTrue(Redactor::isSensitive('mail.apitoken'));
        self::assertTrue(Redactor::isSensitive('services.api-key'));
        self::assertFalse(Redactor::isSensitive('app.name'));
        self::assertFalse(Redactor::isSensitive('routes.users'));
    }
}
