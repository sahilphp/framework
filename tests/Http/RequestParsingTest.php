<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Http\Exception\MethodNotAllowedHttpException;
use Sahil\Http\Request;

/**
 * Request parsing is exercised through the documented test overrides so
 * live superglobals stay untouched during the test process.
 */
final class RequestParsingTest extends TestCase
{
    private const SOURCE = [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/hello/world?name=sahil&flag=1',
        'QUERY_STRING' => 'name=sahil&flag=1',
        'CONTENT_TYPE' => 'text/plain',
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => 'SahilTest/1.0',
        'HTTP_ACCEPT' => 'text/html, application/json',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'SERVER_NAME' => 'localhost',
    ];

    public function test_parses_method_target_path_and_query(): void
    {
        $request = Request::fromGlobals(self::SOURCE, ['name' => 'sahil', 'flag' => '1']);

        self::assertSame('GET', $request->method());
        self::assertSame('/hello/world?name=sahil&flag=1', $request->uri());
        self::assertSame('/hello/world', $request->path());
        self::assertSame('name=sahil&flag=1', $request->queryString());
        self::assertSame(['name' => 'sahil', 'flag' => '1'], $request->query());
        self::assertSame('sahil', $request->queryParameter('name'));
        self::assertNull($request->queryParameter('missing'));
        self::assertSame('fallback', $request->queryParameter('missing', 'fallback'));
        self::assertTrue($request->hasQueryParameter('flag'));
        self::assertFalse($request->hasQueryParameter('nope'));
    }

    public function test_parses_headers_loop_and_safely(): void
    {
        $request = Request::fromGlobals(self::SOURCE, []);

        self::assertSame('SahilTest/1.0', $request->header('User-Agent'));
        self::assertSame('SahilTest/1.0', $request->header('user-agent'));
        self::assertSame('text/html, application/json', $request->header('Accept'));
        self::assertSame('text/plain', $request->header('Content-Type'));
        self::assertNull($request->header('X-Missing'));
        self::assertTrue($request->hasHeader('ACCEPT'));
        self::assertArrayHasKey('User-Agent', $request->headers());
    }

    public function test_cookies_and_forms_are_sanitized(): void
    {
        $request = Request::fromGlobals(self::SOURCE, [], ['session' => 'abc123', 7 => 'illegal', 'bad' => ['array']], ['field' => 'value']);

        self::assertSame('abc123', $request->cookie('session'));
        self::assertNull($request->cookie('missing'));
        self::assertSame(['session' => 'abc123'], $request->cookies(), 'cookies must become string-only map');
        self::assertSame(['field' => 'value'], $request->form());
    }

    public function test_client_ip_reads_remote_addr_only(): void
    {
        $source = self::srv([
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'HTTP_X_REAL_IP' => '5.6.7.8',
        ]);

        $request = Request::fromGlobals($source, []);

        self::assertSame('203.0.113.7', $request->clientIp(), 'forwarded headers are ignored by design');
    }

    public function test_scheme_detection_from_https_variable(): void
    {
        self::assertSame('https', Request::fromGlobals(self::srv(['HTTPS' => 'on']), [])->scheme());
        self::assertSame(null, Request::fromGlobals(self::srv(['HTTPS' => 'off']), [])->scheme());
        self::assertSame('http', Request::fromGlobals(self::srv(['REQUEST_SCHEME' => 'http']), [])->scheme());
        self::assertNull(Request::fromGlobals(self::SOURCE, [])->scheme());
        self::assertFalse(Request::fromGlobals(self::SOURCE, [])->isSecure());
    }

    public function test_absent_request_method_throws_bad_request(): void
    {
        $source = self::SOURCE;
        unset($source['REQUEST_METHOD']);

        self::expectException(\Sahil\Http\Exception\BadRequestHttpException::class);

        Request::fromGlobals($source, []);
    }

    public function test_malformed_method_token_throws_bad_request(): void
    {
        self::expectException(\Sahil\Http\Exception\BadRequestHttpException::class);

        Request::fromGlobals(self::srv(['REQUEST_METHOD' => "GET\r\nX-Injected: 1"]), []);
    }

    public function test_unsupported_methods_throw_405_with_allow_header(): void
    {
        try {
            Request::fromGlobals(self::srv(['REQUEST_METHOD' => 'TRACE']), []);
            self::fail('Expected unsupported method to throw.');
        } catch (MethodNotAllowedHttpException $exception) {
            self::assertSame(405, $exception->statusCode());
            self::assertSame(
                'DELETE, GET, HEAD, OPTIONS, PATCH, POST, PUT',
                $exception->getHeaders()['Allow'],
                'the Allow header must list every supported method',
            );
        }
    }

    public function test_uri_control_characters_throw_bad_request(): void
    {
        self::expectException(\Sahil\Http\Exception\BadRequestHttpException::class);

        Request::fromGlobals(self::srv(['REQUEST_URI' => "/ok\r\ninjected"]), []);
    }

    public function test_paths_are_url_decoded_and_empty_when_root(): void
    {
        self::assertSame('/', Request::fromGlobals(self::srv(['REQUEST_URI' => '/']), [])->path());
        self::assertSame('/a b', Request::fromGlobals(self::srv(['REQUEST_URI' => '/a%20b']), [])->path());
        self::assertSame('/a/b', Request::fromGlobals(self::srv(['REQUEST_URI' => '/a/b?x=1']), [])->path());
    }

    public function test_nul_in_path_rejects_request(): void
    {
        self::expectException(\Sahil\Http\Exception\BadRequestHttpException::class);

        Request::fromGlobals(self::srv(['REQUEST_URI' => '/a%00b']), []);
    }

    public function test_fragment_in_target_rejects_request(): void
    {
        self::expectException(\Sahil\Http\Exception\BadRequestHttpException::class);

        Request::fromGlobals(self::srv(['REQUEST_URI' => '/page#anchor']), []);
    }

    public function test_head_request_exposed_correctly(): void
    {
        $request = Request::fromGlobals(self::srv(['REQUEST_METHOD' => 'HEAD']), []);

        self::assertTrue($request->isHead());
        self::assertSame('HEAD', $request->methodForRouting());
    }

    public function test_json_body_parsing(): void
    {
        $request = Request::fromGlobals(self::srv(['CONTENT_TYPE' => 'application/json']), [], [], [], '{"a":1,"n":[true,null,"s"]}');

        self::assertTrue($request->hasJsonBody());
        self::assertSame(['a' => 1, 'n' => [true, null, 's']], $request->json());
    }

    public function test_json_body_with_charset_parameter_detected(): void
    {
        $request = Request::fromGlobals(self::srv(['CONTENT_TYPE' => 'application/json; charset=utf-8']), [], [], [], '{"x":2}');

        self::assertTrue($request->hasJsonBody());
        self::assertSame(['x' => 2], $request->json());
    }

    public function test_empty_json_body_yields_empty_array(): void
    {
        $request = Request::fromGlobals(self::srv(['CONTENT_TYPE' => 'application/json']), [], [], [], '');

        self::assertSame([], $request->json());
    }

    public function test_malformed_json_body_throws_400_containing_offset(): void
    {
        $request = Request::fromGlobals(self::srv(['CONTENT_TYPE' => 'application/json']), [], [], [], '{"a":');

        try {
            $request->json();
            self::fail('Malformed JSON body must be rejected.');
        } catch (\Sahil\Http\Exception\BadRequestHttpException $exception) {
            self::assertSame(400, $exception->statusCode());
            self::assertStringContainsString('Malformed JSON', $exception->getMessage());
            self::assertStringNotContainsString('a":', $exception->getMessage(), 'raw body must not be echoed back');
        }
    }

    public function test_scalar_json_body_rejected(): void
    {
        $request = Request::fromGlobals(self::srv(['CONTENT_TYPE' => 'application/json']), [], [], [], '"i-am-scalar"');

        self::expectException(\Sahil\Http\Exception\BadRequestHttpException::class);

        $request->json();
    }

    public function test_form_body_detection(): void
    {
        self::assertTrue(Request::fromGlobals(self::srv(['CONTENT_TYPE' => 'application/x-www-form-urlencoded']), [])->hasFormBody());
        self::assertTrue(Request::fromGlobals(self::srv(['CONTENT_TYPE' => 'multipart/form-data']), [])->hasFormBody());
        self::assertTrue(Request::fromGlobals(self::srv(['CONTENT_TYPE' => 'multipart/form-data; boundary=xyz']), [])->hasFormBody());
        self::assertFalse(Request::fromGlobals(self::SOURCE, [])->hasFormBody());
    }

    public function test_body_bytes_are_kept_verbatim(): void
    {
        $request = Request::fromGlobals(self::SOURCE, [], [], [], "\x00raw \xFF bytes");

        self::assertSame("\x00raw \xFF bytes", $request->body());
    }

    public function test_superglobals_never_stay_mutated(): void
    {
        $beforeServer = $_SERVER;
        $beforeGet = $_GET;
        $beforeCookie = $_COOKIE;
        $beforePost = $_POST;

        // Simulate a real request environment for the live-superGlobal path.
        $simulated = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/simulated'];
        $_SERVER = $simulated + $_SERVER;
        $_GET = ['q' => '1'];

        try {
            Request::fromGlobals();
            Request::fromGlobals(self::SOURCE, ['tamper' => 'value']);
        } finally {
            $_SERVER = $beforeServer;
            $_GET = $beforeGet;
        }

        self::assertSame($_SERVER, $beforeServer, 'Request must not capture live superglobal edits');
        self::assertSame($_GET, $beforeGet);
        self::assertSame($_COOKIE, $beforeCookie);
        self::assertSame($_POST, $beforePost);
    }

    /**
     * Build a $_SERVER fixture: known-good base values, selective
     * overrides via array_merge (the `+` operator cannot override).
     */
    private static function srv(array $overrides): array
    {
        return array_merge(self::SOURCE, $overrides);
    }
}
