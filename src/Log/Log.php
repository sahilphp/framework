<?php

declare(strict_types=1);

namespace Sahil\Log;

use Sahil\Foundation\Application;
use Sahil\Support\Logging\LoggerContract;
use Sahil\Support\Logging\LoggingException;
use Sahil\Support\Logging\LogManager;

/**
 * Log — the official logging facade.
 *
 *     Log::info('User registered', ['user_id' => $user->id]);
 *     Log::warning('Gateway slow', ['duration_ms' => 2400]);
 *     Log::channel('stderr')->error('Boot failure');
 *     Log::shareContext(['request_id' => $id]);
 *
 * Thin facade over the container-bound {@see LogManager} (which is also
 * the `LoggerContract` binding) — same convention as `Kapu`/`Webhook`.
 * Context is redacted by {@see Redactor} at the channel envelope.
 */
final class Log
{
    private static ?LogManager $instance = null;

    private function __construct()
    {
    }

    public static function channel(?string $name = null): LoggerContract
    {
        return self::manager()->channel($name);
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        self::manager()->log($level, $message, $context);
    }

    public static function emergency(string $message, array $context = []): void
    {
        self::manager()->emergency($message, $context);
    }

    public static function alert(string $message, array $context = []): void
    {
        self::manager()->alert($message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::manager()->critical($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::manager()->error($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::manager()->warning($message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        self::manager()->notice($message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::manager()->info($message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::manager()->debug($message, $context);
    }

    /** Merge request/job-scoped fields into every subsequent record. */
    public static function shareContext(array $context): void
    {
        self::manager()->shareContext($context);
    }

    /** @return array<string,mixed> the shared context */
    public static function context(): array
    {
        return self::manager()->context();
    }

    /** Drop shared context — request/job boundary. */
    public static function flushContext(): void
    {
        self::manager()->flushContext();
    }

    /**
     * Run inside a merged context that is always restored afterward.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public static function scope(array $context, callable $callback): mixed
    {
        return self::manager()->scope($context, $callback);
    }

    public static function manager(): LogManager
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $application = Application::peek();

        if ($application !== null && $application->container()->has(LogManager::class)) {
            /** @var LogManager $manager */
            $manager = $application->container()->make(LogManager::class);

            return $manager;
        }

        throw new LoggingException(
            'Log needs a configured application with a bound LogManager — or Log::swap() for tests.',
        );
    }

    /** Install the manager the facade delegates to — tests and non-application contexts. */
    public static function swap(?LogManager $manager): void
    {
        self::$instance = $manager;
    }

    /** Drop the swapped instance. */
    public static function flushState(): void
    {
        self::$instance = null;
    }
}
