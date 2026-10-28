<?php

declare(strict_types=1);

namespace Sahil\Tests\Queue;

use RuntimeException;
use Sahil\Contracts\Queue\Jobs\JobContract;

/**
 * Job fixture: appends its name to a static log so the effect survives
 * serialization, and throws when constructed to fail.
 */
final class RecordingJob implements JobContract
{
    /** @var list<string> */
    public static array $handled = [];

    public function __construct(
        public readonly string $name = 'test',
        private readonly bool $fails = false,
        private readonly ?int $attemptBudget = null,
        private readonly ?int $delay = null,
        private readonly ?string $queueName = null,
    ) {
    }

    public function handle(): mixed
    {
        self::$handled[] = $this->name;

        if ($this->fails) {
            throw new RuntimeException(sprintf('Job [%s] failed.', $this->name));
        }

        return null;
    }

    public function queue(): ?string
    {
        return $this->queueName;
    }

    public function maxAttempts(): ?int
    {
        return $this->attemptBudget;
    }

    public function retryDelay(int $attempt): ?int
    {
        return $this->delay;
    }
}
