<?php

declare(strict_types=1);

namespace Sahil\Tests\Notifications;

use Sahil\Contracts\Notifications\NotifiableContract;

/**
 * Notifiable fixture with independently configurable channel routes.
 */
final class TestNotifiable implements NotifiableContract
{
    /**
     * @param array<string, string> $routes
     */
    public function __construct(private readonly array $routes = [])
    {
    }

    public function routeNotificationFor(string $channel): ?string
    {
        return $this->routes[$channel] ?? null;
    }
}
