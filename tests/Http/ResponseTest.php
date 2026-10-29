<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Status;

final class ResponseTest extends TestCase
{
    public function test_text_response_sets_content_type_and_length(): void
    {
        $response = Response::text('hello');

        self::assertSame(200, $response->status());
        self::assertSame('hello', $response->body());
        self::assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('5', $response->header('Content-Length'));
    }

    public function test_json_response_encodes_unescaped_slashes_by_default(): void
    {
        $response = Response::json(['url' => 'https://sahil.test/a']);

        self::assertSame(200, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('{"url":"https://sahil.test/a"}', $response->body());
        self::assertSame((string) strlen('{"url":"https://sahil.test/a"}'), $response->header('Content-Length'));
    }

    public function test_json_roundtrip_is_lossless(): void
    {
        $payload = ['n' => 1, 'flag' => true, 'list' => [1, 'a', null], 'unicode' => 'SahilPHP ✓'];

        $response = Response::json($payload);

        self::assertSame($payload, json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_empty_response_is_204_without_content_type(): void
    {
        $response = Response::empty();

        self::assertSame(204, $response->status());
        self::assertSame('', $response->body());
        self::assertSame('0', $response->header('Content-Length'));
        self::assertNull($response->header('Content-Type'));
    }

    public function test_empty_response_requires_bodyless_status(): void
    {
        self::expectException(\InvalidArgumentException::class);

        Response::empty(200);
    }

    public function test_head_and_204_304_carry_no_body_but_complete_headers(): void
    {
        $request = Request::fromGlobals(['REQUEST_METHOD' => 'HEAD', 'REQUEST_URI' => '/'], []);

        $response = Response::text('this body exists but is never emitted');

        self::assertTrue($response->mustOmitBodyFor($request));
        self::assertSame(
            (string) strlen('this body exists but is never emitted'),
            $response->header('Content-Length'),
            'RFC 7231 4.3.2: HEAD reports the length a GET would produce',
        );
    }

    public function test_redirected_responses(): void
    {
        $response = Response::redirect('https://sahil.test/app', 308);

        self::assertSame(308, $response->status());
        self::assertSame('https://sahil.test/app', $response->header('Location'));
        self::assertSame('0', $response->header('Content-Length'));
    }

    public function test_invalid_redirect_status_rejected(): void
    {
        self::expectException(\InvalidArgumentException::class);

        Response::redirect('/somewhere', 200);
    }

    public function test_header_injection_in_location_rejected(): void
    {
        self::expectException(\InvalidArgumentException::class);

        Response::redirect("https://sahil.test\r\nSet-Cookie: evil=1");
    }

    public function test_response_header_injection_is_rejected(): void
    {
        self::expectException(\InvalidArgumentException::class);

        new Response(200, ['X-Attack' => "v\r\nX-Python: yes"]);
    }

    public function test_status_validation(): void
    {
        self::expectException(\InvalidArgumentException::class);

        new Response(99);
    }

    public function test_status_above_599_rejected(): void
    {
        self::expectException(\InvalidArgumentException::class);

        new Response(600);
    }

    public function test_bodies_are_refused_on_bodyless_statuses(): void
    {
        self::expectException(\InvalidArgumentException::class);

        new Response(204, [], 'this is illegal');
    }

    public function test_status_reason_phrases_and_helpers(): void
    {
        self::assertSame('OK', Status::reason(200));
        self::assertSame('Not Found', Status::reason(404));
        self::assertSame('Unknown', Status::reason(499));
        self::assertTrue(Status::isValid(100));
        self::assertFalse(Status::isValid(99));
        self::assertFalse(Status::isValid(600));
        self::assertTrue(Status::allowsBody(200));
        self::assertTrue(Status::allowsBody(304) === false);
        self::assertTrue(Status::allowsBody(100) === false);
        self::assertTrue(Status::allowsBody(204) === false);
    }

    public function test_with_header_is_immutable(): void
    {
        $base = Response::text('base');
        $with = $base->withHeader('X-Tag', 'a');

        self::assertNull($base->header('X-Tag'));
        self::assertSame('a', $with->header('X-Tag'));
        self::assertSame($base->body(), $with->body());
    }
}
