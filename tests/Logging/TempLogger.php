<?php

declare(strict_types=1);

namespace Sahil\Tests\Logging;

use RuntimeException;

/**
 * A throwaway log file for tests: one per instance, removed when the helper
 * goes out of scope.
 */
final class TempLogger
{
    private string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir().'/sahil-log-'.bin2hex(random_bytes(6)).'.log';

        if (touch($this->path) === false) {
            throw new RuntimeException(sprintf('Unable to create temporary log [%s].', $this->path));
        }
    }

    public function __destruct()
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function count(): int
    {
        return count($this->lines());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $contents = trim((string) file_get_contents($this->path));

        if ($contents === '') {
            return [];
        }

        $lines = [];

        foreach (explode("\n", $contents) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }

        return $lines;
    }
}
