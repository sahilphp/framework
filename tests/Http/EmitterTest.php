<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Http\Emitter\DefaultResponseEmitter;
use Sahil\Http\Emitter\ResponseEmitterContract;
use Sahil\Http\Request;
use Sahil\Http\Response;

final class EmitterTest extends TestCase
{
    public function test_emitting_records_status_headers_and_body(): void
    {
        $captured = new CapturingEmitter();
        $request = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], []);

        $captured->emit(Response::json(['ok' => true]), $request);

        self::assertSame(200, $captured->status);
        self::assertSame('{"ok":true}', $captured->body);
        self::assertSame('application/json', $captured->headers['Content-Type'] ?? null);
        self::assertSame('11', $captured->headers['Content-Length'] ?? null);
    }

    public function test_head_requests_suppress_the_body_but_not_headers(): void
    {
        $captured = new CapturingEmitter();
        $request = Request::fromGlobals(['REQUEST_METHOD' => 'HEAD', 'REQUEST_URI' => '/'], []);

        $captured->emit(Response::text('never seen'), $request);

        self::assertSame('', $captured->body, 'HEAD must not emit a body');
        self::assertSame('10', $captured->headers['Content-Length']);
    }

    public function test_1xx_and_304_responses_never_carry_a_body(): void
    {
        $captured = new CapturingEmitter();
        $request = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], []);

        $captured->emit(Response::empty(304), $request);

        self::assertSame('', $captured->body);
        self::assertSame(304, $captured->status);
    }

    /**
     * The cannot-emit-after-output guard is decisive: run a CGI subprocess
     * that starts output before emitting and expect a fatal rejection.
     */
    public function test_emitting_after_output_started_fails_loudly(): void
    {
        require_once __DIR__.'/../../bootstrap/app.php';

        $projectRoot = dirname(__DIR__, 2);

        $script = $projectRoot.'/storage/framework/emit-after-output-'.uniqid('', true).'.php';

        self::assertTrue(is_dir(dirname($script)) || mkdir(dirname($script), recursive: true) !== false);

        file_put_contents($script, <<<'PHP'
            <?php
            declare(strict_types=1);
            require $argv[1] . '/vendor/autoload.php';
            use Sahil\Http\Response;
            echo "explicit output first\n";
            flush();
            (new Sahil\Http\Emitter\DefaultResponseEmitter())->emit(Response::text('must fail'), null);
            echo "NEVER";
            PHP);

        try {
            $stdoutFile = $script.'.out';
            $stderrFile = $script.'.err';
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $stdoutFile, 'w'],
                2 => ['file', $stderrFile, 'w'],
            ];
            $pipes = [];
            $process = proc_open(
                [PHP_CGI_BINARY, '-n', '-d', 'cgi.force_redirect=0', '-d', 'error_log=', $script, $projectRoot],
                $descriptors,
                $pipes
            );
            self::assertIsResource($process);

            $code = proc_close($process);

            $out = file_get_contents($stdoutFile) ?: '';
            $err = file_get_contents($stderrFile) ?: '';
            @unlink($stdoutFile);
            @unlink($stderrFile);

            // The framework must have thrown HeadersAlreadySentException
            // (a fatal from CGI), and the "NEVER" marker may not appear
            // afterwards via echo.
            self::assertStringContainsString('HeadersAlreadySentException', $out.$err);
            self::assertStringNotContainsString('NEVER', $out);
        } finally {
            @unlink($script);
        }
    }
}

const PHP_CGI_BINARY = 'php-cgi';

final class CapturingEmitter implements ResponseEmitterContract
{
    public int $status = 0;

    /** @var array<string, string> */
    public array $headers = [];

    public string $body = '';

    public function emit(Response $response, ?Request $request = null): void
    {
        $this->status = $response->status();
        $this->headers = [];

        foreach ($response->headers() as $name => $values) {
            $this->headers[$name] = implode(', ', $values);
        }

        $this->body = ($request !== null && $response->mustOmitBodyFor($request)) ? '' : $response->body();
    }
}
