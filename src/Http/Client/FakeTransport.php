<?php

declare(strict_types=1);

namespace Sahil\Http\Client;

use Sahil\Contracts\Http\Client\HttpTransportContract;

/**
 * Test transport — stubbed responses per URL pattern, full request
 * recording, and `preventStray` so a fake can guarantee no real network
 * traffic happens while it's installed.
 *
 * Patterns support `*` wildcards (`'api.example.com/*'`); `'*'` catches
 * everything. First matching pattern wins; unmatched requests throw
 * when the fake is strict.
 */
final class FakeTransport implements HttpTransportContract
{
    /** @var array<string, ClientResponse> */
    private array $stubs = [];

    /** @var list<OutgoingRequest> */
    private array $recorded = [];

    /**
     * @param array<string, ClientResponse|array{status:int,headers?:array,body?:string}|string> $stubs
     */
    public function __construct(
        array $stubs = [],
        private readonly bool $preventStray = true,
    ) {
        foreach ($stubs as $pattern => $response) {
            $this->stub($pattern, $response);
        }
    }

    public function stub(string $pattern, ClientResponse|array|string $response): void
    {
        $this->stubs[$pattern] = match (true) {
            $response instanceof ClientResponse => $response,
            is_string($response) => new ClientResponse(200, [], $response),
            default => new ClientResponse(
                $response['status'],
                $response['headers'] ?? [],
                $response['body'] ?? '',
            ),
        };
    }

    public function send(OutgoingRequest $request): ClientResponse
    {
        $this->recorded[] = $request;

        foreach ($this->stubs as $pattern => $response) {
            if ($this->matches($pattern, $request)) {
                return $response;
            }
        }

        if ($this->preventStray) {
            throw new HttpClientException(sprintf(
                'Faked transport received an unstubbed request: %s %s.',
                $request->method,
                $request->url,
            ));
        }

        return new ClientResponse(200, [], '');
    }

    /** @return list<OutgoingRequest> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * Count requests matching a predicate — for `Http::assertSent()`.
     *
     * @param callable(OutgoingRequest): bool $predicate
     */
    public function countMatching(callable $predicate): int
    {
        return count(array_filter($this->recorded, $predicate));
    }

    public function matches(string $pattern, OutgoingRequest $request): bool
    {
        $target = $request->url;
        $pattern = str_replace(['https://', 'http://'], '', $pattern);
        $hostless = preg_replace('#^https?://#', '', $target);

        if ($pattern === '*') {
            return true;
        }

        if (str_contains($pattern, '*')) {
            return fnmatch($pattern, $hostless, FNM_NOESCAPE) || fnmatch($pattern, $target, FNM_NOESCAPE);
        }

        return $hostless === $pattern || $target === $pattern || str_starts_with($hostless, $pattern);
    }
}
