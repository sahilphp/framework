<?php

declare(strict_types=1);

namespace Sahil\Webhooks\Jobs;

use Sahil\Contracts\Http\Client\HttpClientContract;
use Sahil\Foundation\Application;
use Sahil\Http\Client\HttpClientException;
use Sahil\Queue\Jobs\Job;
use Sahil\Webhooks\WebhookException;
use Sahil\Webhooks\WebhookSigner;
use Sahil\Webhooks\WebhookStore;
use Sahil\Webhooks\WebhookUrlValidator;

/**
 * One queued delivery attempt. Carries only the delivery ID — endpoint,
 * secret and payload are re-resolved from the store at execution time so
 * a deleted/disabled endpoint can never receive a stale send and no
 * secret material enters the queue payload.
 *
 * The queue's own lifecycle owns retries: on a non-final failure the
 * delivery returns to `queued` (bounded error recorded) and the thrown
 * exception triggers release-with-delay; on the final attempt it is
 * marked `failed` and the worker logs it to `failed_jobs`. The delivery
 * ID is stable across retries; the signature timestamp is regenerated
 * per attempt.
 */
final class DeliverWebhookJob extends Job
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly string $deliveryId)
    {
    }

    public function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    public function retryDelay(int $attempt): int
    {
        return min(60 * $attempt, 300); // 60s,120s,… capped at 5 min
    }

    public function handle(): mixed
    {
        $container = Application::peek()->container();
        /** @var WebhookStore $store */
        $store = $container->make(WebhookStore::class);
        /** @var HttpClientContract $http */
        $http = $container->make(HttpClientContract::class);

        $delivery = $store->delivery($this->deliveryId);

        if ($delivery === null) {
            return null; // record removed — nothing to retry
        }

        $endpoint = $store->endpoint($delivery->endpointId);

        if ($endpoint === null || !$endpoint->active) {
            // Endpoint deleted or disabled after enqueue — final, not retried.
            $store->markFailed($this->deliveryId, null, 'Endpoint removed or disabled before delivery.');

            return null;
        }

        $logger = $container->has(\Sahil\Support\Logging\LoggerContract::class)
            ? $container->make(\Sahil\Support\Logging\LoggerContract::class)
            : null;
        $started = microtime(true);

        $store->markAttempt($this->deliveryId);
        $final = $delivery->attempts + 1 >= self::MAX_ATTEMPTS;

        // DNS-aware destination policy, re-run on EVERY attempt: the URL
        // is re-validated, A/AAAA records are resolved fresh, every
        // answer is classified, and the connection below is pinned to
        // exactly these addresses via CURLOPT_RESOLVE — no second lookup,
        // no DNS-rebinding window, no env-proxy detour.
        /** @var WebhookUrlValidator $validator */
        $validator = $container->make(WebhookUrlValidator::class);

        try {
            $destination = $validator->resolve($endpoint->url);
        } catch (WebhookException $e) {
            $this->recordOutcome($store, $final, null, 'Destination rejected: '.$e->getMessage());

            throw $e;
        }

        $timestamp = time();
        $signature = (new WebhookSigner())->sign($endpoint->secret, $timestamp, $delivery->payload);

        try {
            $response = $http
                ->pending()
                ->timeout(15)
                ->connectTimeout(5)
                ->withoutRedirecting() // signatures bind to this URL; never follow redirects
                ->withResolvedAddresses($destination->ips)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Sahil-Event' => $delivery->event,
                    'X-Sahil-Delivery' => $delivery->id,
                    'X-Sahil-Timestamp' => (string) $timestamp,
                    'X-Sahil-Signature' => $signature,
                    'X-Sahil-Signature-Version' => WebhookSigner::VERSION,
                ])
                ->withBody($delivery->payload)
                ->post($endpoint->url);
        } catch (HttpClientException $e) {
            // Transport failure — no HTTP status.
            $this->recordOutcome($store, $final, null, $e->getMessage());
            $logger?->warning('Webhook delivery transport failure', [
                'delivery_id' => $this->deliveryId,
                'endpoint_id' => $delivery->endpointId,
                'event' => $delivery->event,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            throw $e;
        }

        if ($response->successful()) {
            $store->markDelivered($this->deliveryId, $response->status());
            $logger?->info('Webhook delivered', [
                'delivery_id' => $this->deliveryId,
                'endpoint_id' => $delivery->endpointId,
                'event' => $delivery->event,
                'status' => $response->status(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            return null;
        }

        $this->recordOutcome($store, $final, $response->status(), 'HTTP '.$response->status());
        $logger?->warning('Webhook delivery failed', [
            'delivery_id' => $this->deliveryId,
            'endpoint_id' => $delivery->endpointId,
            'event' => $delivery->event,
            'status' => $response->status(),
        ]);

        // Re-throw so the worker retries (or records the final failure).
        throw new HttpClientException(
            sprintf('Webhook delivery [%s] to endpoint [%s] returned HTTP %d.', $delivery->id, $endpoint->id, $response->status()),
            $response,
        );
    }

    private function recordOutcome(WebhookStore $store, bool $final, ?int $status, string $error): void
    {
        if ($final) {
            $store->markFailed($this->deliveryId, $status, $error);
        } else {
            $store->markRetry($this->deliveryId, $status, $error);
        }
    }
}
