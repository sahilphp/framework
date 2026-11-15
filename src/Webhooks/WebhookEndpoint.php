<?php

declare(strict_types=1);

namespace Sahil\Webhooks;

/**
 * A registered webhook endpoint. `secret()` holds the decrypted signing
 * secret only in memory — persistence stores it encrypted and ordinary
 * listings use {@see publicArray()} which never includes it.
 */
final class WebhookEndpoint
{
    /**
     * @param list<string> $events subscribed event names
     */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly array $events,
        public readonly bool $active,
        public readonly string $secret,
        public readonly int $createdAt = 0,
        public readonly int $updatedAt = 0,
    ) {
    }

    public function subscribes(string $event): bool
    {
        return in_array('*', $this->events, true) || in_array($event, $this->events, true);
    }

    /** Listing-safe view — never exposes the secret. */
    public function publicArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'events' => $this->events,
            'active' => $this->active,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function with(bool $active): self
    {
        return new self($this->id, $this->url, $this->events, $active, $this->secret, $this->createdAt, $this->updatedAt);
    }
}
