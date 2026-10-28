<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use Sahil\Support\Logging\LoggerContract;

/**
 * Logger double: keeps every entry so tests can assert what mail reported
 * without touching the filesystem.
 */
final class CollectingLogger implements LoggerContract
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    public function log(string $level, string $message, array $context = []): void
    {
        $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
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

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function entriesAt(string $level): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['level'] === $level,
        ));
    }

    public function last(): ?array
    {
        return $this->entries === [] ? null : $this->entries[array_key_last($this->entries)];
    }
}
