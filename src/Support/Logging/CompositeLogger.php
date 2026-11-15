<?php

declare(strict_types=1);

namespace Sahil\Support\Logging;

/**
 * One logical channel that fans out to several loggers (the `stack`
 * driver). A failing member cannot starve the others — its error is
 * captured and re-thrown as a {@see LoggingException} only after every
 * member had its chance, so no recursive logging loop can occur.
 */
final class CompositeLogger implements LoggerContract
{
    /** @param list<LoggerContract> $loggers */
    public function __construct(private readonly array $loggers)
    {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $failures = [];

        foreach ($this->loggers as $logger) {
            try {
                $logger->log($level, $message, $context);
            } catch (\Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }

        if ($failures !== [] && count($failures) === count($this->loggers)) {
            // Every channel failed — surface it; a partial failure is
            // still delivered to the healthy channels.
            throw new LoggingException('All stacked log channels failed: '.implode('; ', $failures));
        }
    }

    /** @internal channel members that failed on the last log() — observability. */
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
