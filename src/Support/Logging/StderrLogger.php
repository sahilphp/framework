<?php

declare(strict_types=1);

namespace Sahil\Support\Logging;

use Sahil\Support\Redactor;

/**
 * STDERR logger — for process managers and container log collectors.
 *
 * NEVER writes to STDOUT (that would corrupt command output/protocols).
 * The stream is injectable for deterministic tests; production uses
 * `php://stderr`. Same envelope, redaction and threshold as
 * {@see FileLogger}, `json` or `line` format.
 */
final class StderrLogger implements LoggerContract
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream writable stream — default `php://stderr`
     */
    public function __construct(
        $stream = null,
        private readonly string $threshold = 'debug',
        private readonly string $format = 'line',
        private readonly string $channel = 'stderr',
    ) {
        if (!array_key_exists($threshold, FileLogger::SEVERITY)) {
            throw new \OutOfRangeException(sprintf('Unknown log threshold [%s].', $threshold));
        }

        if (!in_array($format, ['json', 'line'], true)) {
            throw new \OutOfRangeException(sprintf('Unknown log format [%s] — use json|line.', $format));
        }

        if ($stream === null) {
            $stream = fopen('php://stderr', 'wb');
        }

        if (!is_resource($stream)) {
            throw new LoggingException('StderrLogger needs a writable stream.');
        }

        $this->stream = $stream;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);

        if ((FileLogger::SEVERITY[$level] ?? 99) < FileLogger::SEVERITY[$this->threshold]) {
            return;
        }

        $event = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'channel' => $this->channel,
            'message' => $message,
            'context' => Redactor::flattenSafely($context),
        ];

        $line = $this->format === 'json'
            ? json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            : LogLineFormatter::render($event);

        if (@fwrite($this->stream, $line) === false) {
            throw new LoggingException('Could not write to the stderr log stream.');
        }
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
