<?php

declare(strict_types=1);

namespace Sahil\Tests\Logging;

use PHPUnit\Framework\TestCase;
use Sahil\Foundation\Application;
use Sahil\Foundation\CentralExceptionHandler;
use Sahil\Foundation\ServiceProvider;
use Sahil\Http\Exception\MethodNotAllowedHttpException;
use Sahil\Support\Logging\FileLogger;
use Sahil\Support\Logging\LoggerContract;

/**
 * Phase-5 tests: structured file logging, threshold filtering, secret
 * redaction at the log boundary, and the centralized exception handler.
 */
final class LoggingAndExceptionHandlingTest extends TestCase
{
    private string $logFile = '';

    protected function tearDown(): void
    {
        Application::free();

        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    private static function temporaryDir(): string
    {
        $dir = sys_get_temp_dir().'/sahil-logs-'.bin2hex(random_bytes(4));
        mkdir($dir, recursive: true);

        return $dir;
    }

    public function test_file_logger_writes_structured_json_lines(): void
    {
        $dir = self::temporaryDir();
        $this->logFile = $dir.'/app.log';

        $logger = new FileLogger($this->logFile);
        $logger->info('User registered', ['user_id' => 7, 'channel' => 'web']);

        $line = (string) file_get_contents($this->logFile);
        $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('info', $event['level']);
        self::assertSame('User registered', $event['message']);
        self::assertSame(['user_id' => 7, 'channel' => 'web'], $event['context']);
        self::assertArrayHasKey('timestamp', $event);
        self::assertStringEndsWith("\n", $line, 'line-delimited');
    }

    public function test_threshold_orders_are_respected(): void
    {
        $dir = self::temporaryDir();
        $this->logFile = $dir.'/app.log';

        $logger = new FileLogger($this->logFile, threshold: 'error');

        $logger->debug('fine detail');
        $logger->notice('minor note');
        $logger->error('paid attention', ['id' => 3]);
        $logger->critical('disaster');

        $lines = array_filter(explode("\n", trim((string) file_get_contents($this->logFile))));

        $levels = [];
        foreach ($lines as $line) {
            $levels[] = json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR)['level'];
        }

        self::assertSame(['error', 'critical'], $levels);
    }

    public function test_unknown_threshold_is_rejected(): void
    {
        $this->expectException(\OutOfRangeException::class);

        new FileLogger(sys_get_temp_dir().'/nope.log', 'verbosity-9');
    }

    public function test_logger_never_persists_secret_context_values(): void
    {
        $dir = self::temporaryDir();
        $this->logFile = $dir.'/app.log';

        $logger = new FileLogger($this->logFile);
        $logger->error('Login attempt', [
            'username' => 'jane',
            'password' => 'hunter2',
            'api_token' => 'sk-live-yeah',
        ]);

        $logged = (string) file_get_contents($this->logFile);

        self::assertStringNotContainsString('hunter2', $logged);
        self::assertStringNotContainsString('sk-live-yeah', $logged);
        self::assertStringContainsString('jane', $logged);
        self::assertStringContainsString('[redacted]', $logged);
    }

    public function test_central_handler_reports_http_as_warning_and_faults_as_errors(): void
    {
        $logger = new RecordingLogger();
        $handler = new CentralExceptionHandler($logger);

        $handler->report(new \Sahil\Http\Exception\NotFoundHttpException('missing page'));
        $handler->report(new \RuntimeException('genuine fault'));

        self::assertSame(
            ['warning', 'error'],
            array_map(static fn (array $call): string => $call['level'], $logger->events),
        );
        self::assertStringContainsString('NotFoundHttpException', $logger->events[0]['message']);
    }

    public function test_central_handler_rendering_keeps_http_and_states_errors(): void
    {
        $handler = new CentralExceptionHandler(new RecordingLogger());

        // HTTP exceptions: status + Allow headers survive.
        $notFound = $handler->render(new \Sahil\Http\Exception\NotFoundHttpException(), debug: false);
        self::assertSame(404, $notFound->status());

        $method = $handler->render(
            new MethodNotAllowedHttpException(['GET'], 'nope'),
            debug: false,
        );
        self::assertSame(405, $method->status());
        self::assertSame('GET', $method->header('Allow'));

        // Production: nothing but the generic error body.
        $production = $handler->render(new \RuntimeException('db down: 10.0.0.7'), debug: false);
        self::assertSame(500, $production->status());
        self::assertSame('{"error":"server_error"}', $production->body());
        self::assertStringNotContainsString('10.0.0.7', $production->body());

        // Debug (explicit opt-in): message and class, still no stack trace.
        $debug = $handler->render(new \RuntimeException('db down'), debug: true);
        self::assertSame('{"error":"server_error","message":"db down","exception":"RuntimeException"}', $debug->body());
    }

    public function test_application_builds_the_observability_chain_via_container(): void
    {
        $app = Application::configure(__DIR__.'/../Fixtures/app-root');
        $app->register(new class ($app) extends ServiceProvider {});
        $app->boot();

        $container = $app->container();
        $container->singleton(
            LoggerContract::class,
            static fn (\Sahil\Container\Container $c) => new RecordingLogger(),
        );
        $container->singleton(
            CentralExceptionHandler::class,
            static fn (\Sahil\Container\Container $c): CentralExceptionHandler
                => new CentralExceptionHandler($c->make(LoggerContract::class)),
        );

        $handler = $container->make(CentralExceptionHandler::class);

        $notFound = $handler->render(new \Sahil\Http\Exception\NotFoundHttpException(), debug: false);
        self::assertSame(404, $notFound->status());
    }
}

final class RecordingLogger implements LoggerContract
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $events = [];

    public function log(string $level, string $message, array $context = []): void
    {
        $this->events[] = ['level' => $level, 'message' => $message, 'context' => $context];
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
