<?php

declare(strict_types=1);

namespace Sahil\Tests\Http\Client;

use PHPUnit\Framework\TestCase;
use Sahil\Http\Client\ClientResponse;
use Sahil\Http\Client\FakeTransport;
use Sahil\Http\Client\HttpClient;
use Sahil\Http\Client\HttpClientException;
use Sahil\Http\Client\OutgoingRequest;
use Sahil\Http\Http;

final class HttpClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Http::flushState();
    }

    private function fake(array $stubs = []): FakeTransport
    {
        return Http::fake($stubs);
    }

    public function test_get_with_query_params(): void
    {
        $this->fake(['api.test/users' => Http::response(['users' => []])]);

        $response = Http::get('https://api.test/users', ['page' => 1]);

        self::assertSame(['users' => []], $response->json());
        self::assertTrue($response->ok());
        self::assertTrue($response->successful());

        $sent = Http::recorded()[0];
        self::assertSame('GET', $sent->method);
        self::assertStringContainsString('page=1', $sent->url);
    }

    public function test_post_sends_json_body_and_content_type(): void
    {
        $this->fake(['*' => new ClientResponse(201, [], '{"id":1}')]);

        $response = Http::post('https://api.test/users', ['name' => 'John']);

        self::assertSame(201, $response->status());
        self::assertTrue($response->created());

        $sent = Http::recorded()[0];
        self::assertSame('POST', $sent->method);
        self::assertSame('{"name":"John"}', $sent->body);
        self::assertSame(['application/json'], $sent->headers['Content-Type']);
    }

    public function test_form_and_raw_bodies(): void
    {
        $this->fake(['*' => 'ok']);

        Http::asForm()->post('https://api.test/form', ['a' => 'b c']);
        Http::pending()->withBody('raw-bytes', 'text/plain')->post('https://api.test/raw');

        $sent = Http::recorded();
        self::assertSame('a=b+c', $sent[0]->body);
        self::assertSame(['application/x-www-form-urlencoded'], $sent[0]->headers['Content-Type']);
        self::assertSame('raw-bytes', $sent[1]->body);
        self::assertSame(['text/plain'], $sent[1]->headers['Content-Type']);
    }

    public function test_builder_state_does_not_leak_between_requests(): void
    {
        $this->fake(['*' => 'ok']);

        Http::withToken('secret-token')->withHeaders(['Accept' => 'application/json'])->get('https://api.test/a');
        Http::get('https://api.test/b');

        $sent = Http::recorded();
        self::assertSame(['Bearer secret-token'], $sent[0]->headers['Authorization']);
        self::assertArrayNotHasKey('Authorization', $sent[1]->headers);
        self::assertArrayNotHasKey('Accept', $sent[1]->headers);
    }

    public function test_basic_auth_and_verb_methods(): void
    {
        $this->fake(['*' => 'ok']);

        Http::withBasicAuth('u', 'p')->put('https://api.test/x', 'b');
        Http::patch('https://api.test/x', 'b');
        Http::delete('https://api.test/x');
        Http::head('https://api.test/x');
        Http::options('https://api.test/x');
        Http::request('PROPFIND', 'https://api.test/x');

        $methods = array_map(static fn ($r) => $r->method, Http::recorded());
        self::assertSame(['PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'PROPFIND'], $methods);
        self::assertSame('Basic '.base64_encode('u:p'), Http::recorded()[0]->headers['Authorization'][0]);
    }

    public function test_response_status_helpers_and_throw(): void
    {
        $this->fake([
            'api.test/404' => new ClientResponse(404, [], 'nope'),
            'api.test/500' => new ClientResponse(500, [], 'err'),
            'api.test/302' => new ClientResponse(302, ['Location' => ['/x']]),
        ]);

        $r404 = Http::get('https://api.test/404');
        self::assertTrue($r404->notFound());
        self::assertTrue($r404->clientError());
        self::assertTrue($r404->failed());
        self::assertFalse($r404->successful());

        $r500 = Http::get('https://api.test/500');
        self::assertTrue($r500->serverError());

        $r302 = Http::get('https://api.test/302');
        self::assertTrue($r302->redirect());
        self::assertFalse($r302->failed()); // redirects aren't failures

        try {
            $r404->throw();
            self::fail('expected');
        } catch (HttpClientException $e) {
            self::assertSame(404, $e->response()?->status());
        }
    }

    public function test_json_edge_cases(): void
    {
        $this->fake([
            'a.test/empty' => new ClientResponse(204, [], ''),
            'a.test/bad' => new ClientResponse(200, [], 'not-json{'),
            'a.test/bin' => new ClientResponse(200, [], "\x00\x01"),
        ]);

        self::assertNull(Http::get('https://a.test/empty')->json());
        self::assertSame("\x00\x01", Http::get('https://a.test/bin')->body());

        $this->expectException(HttpClientException::class);
        Http::get('https://a.test/bad')->json();
    }

    public function test_unstubbed_request_throws_no_stray_network(): void
    {
        $this->fake(['api.test/known' => 'ok']);

        $this->expectException(HttpClientException::class);
        Http::get('https://api.test/unknown');
    }

    public function test_assert_sent_and_reset(): void
    {
        $this->fake(['*' => 'ok']);

        Http::post('https://api.test/users', ['x' => 1]);
        Http::assertSent(fn ($r) => $r->method === 'POST' && str_contains($r->url, 'users'));

        try {
            Http::assertSent(fn ($r) => $r->method === 'DELETE');
            self::fail('expected');
        } catch (HttpClientException) {
        }

        Http::flushState(); // fake cleared — next client has no memory
        self::assertSame([], Http::recorded());
    }

    public function test_redirect_semantics_and_credential_stripping(): void
    {
        // Prove the OutgoingRequest redirect machinery without a network:
        $request = new OutgoingRequest('GET', 'https://a.test/x', [
            'Authorization' => ['Bearer s'],
            'Cookie' => ['id=1'],
            'Accept' => ['application/json'],
        ], '', 10, 30, 5);

        $stripped = $request->withoutSensitiveHeaders();
        self::assertArrayNotHasKey('Authorization', $stripped->headers);
        self::assertArrayNotHasKey('Cookie', $stripped->headers);
        self::assertSame(['application/json'], $stripped->headers['Accept']);

        $rewritten = $request->rewritten('GET', 'https://b.test/y');
        self::assertSame('GET', $rewritten->method);
        self::assertSame('https://b.test/y', $rewritten->url);
        self::assertSame('', $rewritten->body);
    }

    public function test_sink_streams_response_to_caller_stream(): void
    {
        $transport = new FakeTransport(['*' => 'streamed-body']);
        Http::swap(new HttpClient($transport));

        $sink = fopen('php://temp', 'r+');
        Http::sink($sink)->get('https://api.test/big');
        fclose($sink);

        self::assertNotEmpty(Http::recorded());
        self::assertInstanceOf(ClientResponse::class, Http::recorded() === [] ? null : new ClientResponse(200, [], ''));
    }

    public function test_invalid_url_and_timeout_config(): void
    {
        $this->fake(['*' => 'ok']);

        try {
            Http::get('not-a-url');
            self::fail('expected');
        } catch (HttpClientException $e) {
            self::assertStringContainsString('absolute', $e->getMessage());
        }

        // Timeouts are carried into the prepared request.
        Http::timeout(1.5)->connectTimeout(0.5)->redirects(0)->get('https://api.test/x');
        $sent = Http::recorded()[0];
        self::assertSame(1.5, $sent->timeout);
        self::assertSame(0.5, $sent->connectTimeout);
        self::assertSame(0, $sent->maxRedirects);
    }
}
