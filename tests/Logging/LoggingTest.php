<?php

declare(strict_types=1);

namespace Sahil\Tests\Logging;

use PHPUnit\Framework\TestCase;
use Sahil\Foundation\Application;
use Sahil\Http\Middleware\RequestIdMiddleware;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Log\Log;
use Sahil\Support\Logging\FileLogger;
use Sahil\Support\Logging\LoggingException;
use Sahil\Support\Logging\LogManager;
use Sahil\Support\Logging\StderrLogger;
use Sahil\Testing\TestLogger;

final class LoggingTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir().'/sahil-log-'.bin2hex(random_bytes(6)).'.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        Log::flushState();
        Application::free();
    }

    private function manager(array $extra = []): LogManager
    {
        return new LogManager([
            'file' => new FileLogger($this->logFile),
            'stderr' => new StderrLogger($this->memoryStream()),
            'test' => new TestLogger(),
        ] + $extra);
    }

    private function memoryStream()
    {
        return fopen('php://memory', 'wb+');
    }

    // --- core API ------------------------------------------------------------

    public function test_all_severity_levels_and_filtering(): void
    {
        $manager = new LogManager(['file' => new FileLogger($this->logFile, 'warning')]);

        $manager->debug('d');
        $manager->info('i');
        $manager->notice('n');
        $manager->warning('w');
        $manager->error('e');
        $manager->critical('c');
        $manager->alert('a');
        $manager->emergency('em');

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES);
        self::assertCount(5, $lines); // warning+
        $levels = array_map(static fn (string $l): string => json_decode($l, true)['level'], $lines);
        self::assertSame(['warning', 'error', 'critical', 'alert', 'emergency'], $levels);
    }

    public function test_context_and_channel_selection(): void
    {
        $manager = $this->manager();
        $manager->channel('file')->info('hello', ['user_id' => 7]);

        $event = json_decode(file_get_contents($this->logFile), true);
        self::assertSame('info', $event['level']);
        self::assertSame('file', $event['channel']);
        self::assertSame('hello', $event['message']);
        self::assertSame(7, $event['context']['user_id']);
    }

    public function test_unknown_channel_fails_loudly(): void
    {
        $this->expectException(LoggingException::class);
        $this->manager()->channel('nope')->info('x');
    }

    public function test_stack_channel_fans_out_and_survives_one_failure(): void
    {
        $manager = new LogManager([
            'file' => new FileLogger($this->logFile),
            'bad' => new FailingLogger(),
            'stack' => ['file', 'bad'],
        ], 'stack');

        $manager->info('fanned');

        self::assertStringContainsString('fanned', file_get_contents($this->logFile)); // file still got it
    }

    public function test_stack_throws_only_when_every_channel_fails(): void
    {
        $manager = new LogManager(['bad' => new FailingLogger(), 'stack' => ['bad']], 'stack');

        $this->expectException(LoggingException::class);
        $manager->info('lost');
    }

    // --- stderr + formats ----------------------------------------------------

    public function test_stderr_writes_line_format_and_never_stdout(): void
    {
        $stream = fopen('php://memory', 'wb+');
        $logger = new StderrLogger($stream);
        $logger->error('boom', ['what' => 'x']);

        rewind($stream);
        $line = stream_get_contents($stream);
        self::assertStringContainsString('ERROR', $line);
        self::assertStringContainsString('boom', $line);
        self::assertStringContainsString('"what":"x"', $line);
        fclose($stream);
    }

    public function test_file_logger_line_format(): void
    {
        (new FileLogger($this->logFile, 'debug', 'line'))->info('dev line', ['a' => 1]);
        $line = file_get_contents($this->logFile);
        self::assertStringContainsString('INFO file: dev line {"a":1}', $line);
    }

    // --- redaction -----------------------------------------------------------

    public function test_secrets_never_reach_the_stream(): void
    {
        $logger = new FileLogger($this->logFile);
        $logger->info('ctx', [
            'password' => 'hunter2',
            'nested' => ['api_key' => 'sek-99999', 'token' => 't0k-value'],
            'safe' => 'visible',
            'headers' => ['authorization' => 'Bearer abc', 'cookie' => 'sess=xyz'],
            'webhook_secret' => 'whsec',
            'session_id' => 's3ss',
        ]);

        $out = file_get_contents($this->logFile);
        foreach (['hunter2', 'sek-99999', 't0k-value', 'Bearer abc', 'sess=xyz', 'whsec', 's3ss'] as $secret) {
            self::assertStringNotContainsString($secret, $out, "leaked {$secret}");
        }
        self::assertStringContainsString('visible', $out);
        self::assertStringContainsString('[redacted]', $out);
    }

    // --- shared context / worker isolation -----------------------------------

    public function test_shared_context_merges_and_scopes_restore(): void
    {
        $manager = $this->manager();
        $manager->shareContext(['app' => 'sahil']);

        $result = $manager->scope(['request_id' => 'r1'], function () use ($manager): string {
            $manager->info('inside');

            return $manager->context()['request_id'];
        });

        self::assertSame('r1', $result);
        self::assertSame(['app' => 'sahil'], $manager->context()); // restored

        $manager->info('outside');
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES);
        self::assertSame('r1', json_decode($lines[0], true)['context']['request_id']);
        self::assertArrayNotHasKey('request_id', json_decode($lines[1], true)['context']);
    }

    public function test_exception_inside_scope_restores_context(): void
    {
        $manager = $this->manager();
        $manager->shareContext(['base' => 1]);

        try {
            $manager->scope(['job' => 'x'], static function (): void {
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(['base' => 1], $manager->context());
    }

    public function test_sequential_requests_do_not_leak_ids(): void
    {
        $manager = $this->manager();
        $middleware = new RequestIdMiddleware(logs: $manager);
        $handler = static fn (Request $r): Response => Response::text('ok', 200);

        $middleware->handle(Request::fromGlobals(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/a'], cookies: []), $handler);
        self::assertSame([], $manager->context()); // scope popped — no leak

        $middleware->handle(Request::fromGlobals(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/b'], cookies: []), $handler);
        self::assertSame([], $manager->context());
    }

    // --- correlation ids ------------------------------------------------------

    public function test_request_id_generated_and_echoed(): void
    {
        $response = (new RequestIdMiddleware())->handle(
            Request::fromGlobals(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], cookies: []),
            static fn (Request $r): Response => Response::text('ok', 200),
        );

        $id = $response->header('X-Request-Id');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    public function test_valid_incoming_id_is_reused_invalid_is_replaced(): void
    {
        $m = new RequestIdMiddleware(trustIncoming: true);
        $handler = static fn (Request $r): Response => Response::text('ok', 200);

        $good = Request::fromGlobals(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_X_REQUEST_ID' => 'corr-abc_123.xyz'], cookies: []);
        self::assertSame('corr-abc_123.xyz', $m->handle($good, $handler)->header('X-Request-Id'));

        foreach (['<script>', 'a', str_repeat('x', 200), "evil\nid", ' spaces '] as $bad) {
            $req = Request::fromGlobals(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_X_REQUEST_ID' => $bad], cookies: []);
            $out = $m->handle($req, $handler)->header('X-Request-Id');
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $out, "bad id {$bad} reused");
        }
    }

    // --- facade ---------------------------------------------------------------

    public function test_log_facade_resolves_the_bound_manager(): void
    {
        $manager = $this->manager();
        $app = Application::configure(sys_get_temp_dir());
        $app->container()->instance(LogManager::class, $manager);

        Log::info('via facade', ['x' => 1]);
        Log::shareContext(['rid' => 'r1']);
        Log::warning('with ctx');

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES);
        self::assertSame('via facade', json_decode($lines[0], true)['message']);
        self::assertSame('r1', json_decode($lines[1], true)['context']['rid']);

        Log::flushContext();
        self::assertSame([], $manager->context());
    }
}

final class FailingLogger implements \Sahil\Support\Logging\LoggerContract
{
    public function log(string $level, string $message, array $context = []): void
    {
        throw new \RuntimeException('sink down');
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}
