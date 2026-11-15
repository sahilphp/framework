<?php

declare(strict_types=1);

namespace Sahil\Support\Logging;

/**
 * Channel manager + shared-context scope — the object the `Log` facade
 * and container resolve.
 *
 * - `channel('stderr')` resolves a configured channel; `log()` uses the
 *   default channel.
 * - `stack` channels are {@see CompositeLogger}s built from named
 *   channels — one broken channel never starves the others.
 * - `shareContext()` adds request/job-scoped fields to every record;
 *   `scope()` runs a callable inside a merged context and ALWAYS
 *   restores the previous one (try/finally) — sequential requests and
 *   jobs cannot leak context.
 */
final class LogManager implements LoggerContract
{
    /** @var array<string, LoggerContract> */
    private array $channels = [];

    /** @var array<string, mixed> */
    private array $sharedContext = [];

    /**
     * @param array<string, LoggerContract|list<string>> $channels name =>
     *        logger, or a list of channel names composing a stack
     */
    public function __construct(
        array $channels,
        private readonly string $default = 'file',
    ) {
        foreach ($channels as $name => $channel) {
            if (is_array($channel)) {
                $loggers = [];

                foreach ($channel as $member) {
                    $member = $channels[$member] ?? null;

                    if ($member instanceof LoggerContract) {
                        $loggers[] = $member;
                    }
                }

                $this->channels[$name] = new CompositeLogger($loggers);
            } else {
                $this->channels[$name] = $channel;
            }
        }
    }

    /** The named channel (or the default) for direct use. */
    public function channel(?string $name = null): LoggerContract
    {
        $name ??= $this->default;
        $logger = $this->channels[$name] ?? null;

        if ($logger === null) {
            throw new LoggingException(sprintf(
                'Log channel [%s] is not configured (have: %s).',
                $name,
                implode(', ', array_keys($this->channels)) ?: 'none',
            ));
        }

        return $logger;
    }

    /** Merge fields into the shared context attached to every record. */
    public function shareContext(array $context): void
    {
        $this->sharedContext = array_merge($this->sharedContext, $context);
    }

    /** All shared fields (redaction happens at the channel envelope). */
    public function context(): array
    {
        return $this->sharedContext;
    }

    /** Drop all shared context — request/job boundary. */
    public function flushContext(): void
    {
        $this->sharedContext = [];
    }

    /**
     * Run `$callback` with `$context` merged into the shared context,
     * restoring the previous context afterward — nested scopes nest
     * correctly and exceptions cannot strand context.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function scope(array $context, callable $callback): mixed
    {
        $previous = $this->sharedContext;
        $this->sharedContext = array_merge($this->sharedContext, $context);

        try {
            return $callback();
        } finally {
            $this->sharedContext = $previous;
        }
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $merged = $context + $this->sharedContext; // call-site wins
        $this->channel()->log($level, $message, $merged);
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
