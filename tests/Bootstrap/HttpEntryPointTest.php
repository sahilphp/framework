<?php

declare(strict_types=1);

namespace Sahil\Tests\Bootstrap;

use PHPUnit\Framework\TestCase;

final class HttpEntryPointTest extends TestCase
{
    /**
     * @var resource|null
     */
    private $serverProcess = null;

    public function test_health_endpoint_serves_expected_json_response(): void
    {
        [$status, $body, $contentType] = $this->request('/health');

        self::assertSame(200, $status);
        self::assertSame('application/json', $contentType);

        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ok', $payload['status']);
        self::assertSame('SahilPHP', $payload['framework']);
        self::assertTrue($payload['booted']);
        self::assertSame(PHP_VERSION, $payload['php']);
    }

    public function test_unknown_paths_respond_with_safe_404(): void
    {
        [$status, $body] = $this->request('/no-such-page');

        self::assertSame(404, $status);
        self::assertSame(
            ['error' => 'http_error', 'status' => 404],
            json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            'kernel-rendered 404: status shown, no application internals'
        );
    }

    public function test_no_stack_trace_or_installation_path_leaks_into_error_responses(): void
    {
        [, $body] = $this->request('/no-such-page');

        self::assertStringNotContainsString('Fatal error', $body);
        self::assertStringNotContainsString('/home/', $body);
    }

    protected function tearDown(): void
    {
        if ($this->serverProcess !== null) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
            $this->serverProcess = null;
        }
    }

    /**
     * Start the PHP built-in server running our front controller on a free
     * port, wait for readiness, fetch the given path once, and answer
     * with [status code, body, content-type].
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function request(string $path): array
    {
        $this->startBuiltInServer();

        $status = 0;
        $body = '';
        $contentType = '';

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
            $response = @file_get_contents('http://127.0.0.1:8931'.$path, false, $context);

            if ($response !== false) {
                $body = $response;
                break;
            }

            usleep(100_000);
        }

        self::assertNotSame('', $body, 'Server never answered within the timeout.');

        $contentType = '';
        $statusLine = '';

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $statusLine = $header;
                $status = (int) $matches[1];
            } elseif (str_starts_with(strtolower($header), 'content-type:')) {
                $contentType = trim(strtolower(substr($header, 13)));
            }
        }

        self::assertSame(true, $statusLine !== '');

        return [$status, $body, $contentType];
    }

    private function startBuiltInServer(): void
    {
        if (is_resource($this->serverProcess)) {
            return;
        }

        $entryPoint = realpath(__DIR__.'/../../public').'/index.php';

        $command = [PHP_BINARY, '-S', '127.0.0.1:8931', $entryPoint];
        $spec = [
            1 => ['file', '/tmp/opencode/sahil-server.log', 'a'],
            2 => ['file', '/tmp/opencode/sahil-server.err.log', 'a'],
        ];

        $handle = proc_open($command, $spec, $pipes);

        self::assertIsResource($handle);

        $this->serverProcess = $handle;
    }
}
