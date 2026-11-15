<?php

declare(strict_types=1);

namespace Sahil\Webhooks;

use Sahil\Foundation\Application;

/**
 * Webhook — the official outbound-webhook API.
 *
 *     $endpoint = Webhook::register('https://hooks.example.com/sahil', ['user.registered']);
 *     Webhook::deliver('user.registered', ['user_id' => $user->id]);
 *     Webhook::rotateSecret($endpoint->id);
 *     Webhook::disable($endpoint->id);
 *
 * Thin facade over the container-bound {@see WebhookManager} — same
 * convention as `Kapu`/`Event`. Endpoints are persisted (secrets
 * encrypted via `Encrypter`), deliveries run on the existing queue,
 * requests go through the native `Http` client.
 */
final class Webhook
{
    private static ?WebhookManager $instance = null;

    private function __construct()
    {
    }

    /**
     * Register an endpoint. `$events` is a list of event names (or
     * `['*']`). The plaintext secret is visible only on the returned
     * endpoint — share it with the receiver; rotation via
     * {@see rotateSecret()}.
     */
    public static function register(string $url, array $events, ?string $secret = null): WebhookEndpoint
    {
        return self::manager()->register($url, $events, $secret);
    }

    public static function endpoint(string $id): ?WebhookEndpoint
    {
        return self::manager()->endpoint($id);
    }

    /** @return list<array<string,mixed>> secret-free listings */
    public static function endpoints(): array
    {
        return self::manager()->endpoints();
    }

    public static function update(string $id, ?string $url = null, ?array $events = null): WebhookEndpoint
    {
        return self::manager()->update($id, $url, $events);
    }

    public static function enable(string $id): WebhookEndpoint
    {
        return self::manager()->enable($id);
    }

    public static function disable(string $id): WebhookEndpoint
    {
        return self::manager()->disable($id);
    }

    /** Rotate the signing secret; plaintext appears only on the returned endpoint. */
    public static function rotateSecret(string $id): WebhookEndpoint
    {
        return self::manager()->rotateSecret($id);
    }

    public static function unregister(string $id): bool
    {
        return self::manager()->unregister($id);
    }

    /**
     * Dispatch a named event payload to all matching active endpoints —
     * one queued delivery each, dispatched independently (one endpoint's
     * failure never blocks another).
     *
     * @return list<WebhookDelivery> the queued deliveries
     */
    public static function deliver(string $event, array $payload): array
    {
        return self::manager()->deliver($event, $payload);
    }

    public static function delivery(string $id): ?WebhookDelivery
    {
        return self::manager()->delivery($id);
    }

    /** @return list<WebhookDelivery> */
    public static function deliveriesFor(string $endpointId, int $limit = 50): array
    {
        return self::manager()->deliveriesFor($endpointId, $limit);
    }

    public static function manager(): WebhookManager
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $application = Application::peek();

        if ($application !== null && $application->container()->has(WebhookManager::class)) {
            /** @var WebhookManager $manager */
            $manager = $application->container()->make(WebhookManager::class);

            return $manager;
        }

        throw new WebhookException(
            'Webhook needs a configured application with a bound WebhookManager — or Webhook::swap() for tests.',
        );
    }

    /** Install the manager the facade delegates to — tests and non-application contexts. */
    public static function swap(?WebhookManager $manager): void
    {
        self::$instance = $manager;
    }

    /** Drop the swapped instance. */
    public static function flushState(): void
    {
        self::$instance = null;
    }
}
