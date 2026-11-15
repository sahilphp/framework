<?php

declare(strict_types=1);

namespace Sahil\Tests\OpenApi;

use PHPUnit\Framework\TestCase;
use Sahil\Http\Resources\Resource;
use Sahil\Http\Response;
use Sahil\Http\Router;
use Sahil\OpenApi\Generator;
use Sahil\OpenApi\OpenApi;
use Sahil\OpenApi\SchemaMapper;
use Sahil\Routing\Link;

final class DocUserResource extends Resource
{
    public function toArray(): array
    {
        return ['id' => $this->id];
    }

    public static function apiSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
            'required' => ['id', 'name'],
        ];
    }
}

final class UndocResource extends Resource
{
    public function toArray(): array
    {
        return [];
    }
}

final class OpenApiTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->register(
            Link::get('/api/users', static fn () => Response::json([]))
                ->name('api.users')->middleware('auth:api'),
            Link::post('/api/users', static fn () => Response::json([], 201))
                ->name('api.users.store')->middleware('auth:api'),
            Link::get('/api/users/{id}', static fn () => Response::json([]))
                ->name('api.users.show')->middleware(['auth:api', 'token:users:read']),
            Link::delete('/api/users/{id}', static fn () => Response::empty())
                ->name('api.users.destroy')->middleware('auth:api'),
            Link::get('/health', static fn () => Response::json(['ok' => true]))->name('health'),
        );

        return $r;
    }

    private function generator(): Generator
    {
        return new Generator();
    }

    /* ---------- discovery ---------- */

    public function test_routes_methods_names_and_path_params(): void
    {
        $doc = $this->generator()->generate($this->router()->routes(), []);
        self::assertSame('3.1.0', $doc['openapi']);
        self::assertArrayHasKey('/api/users', $doc['paths']);
        self::assertArrayHasKey('get', $doc['paths']['/api/users']);
        self::assertArrayHasKey('post', $doc['paths']['/api/users']);
        $params = $doc['paths']['/api/users/{id}']['get']['parameters'];
        self::assertSame('id', $params[0]['name']);
        self::assertSame('path', $params[0]['in']);
        self::assertTrue($params[0]['required']);
        self::assertSame('api_users', $doc['paths']['/api/users']['get']['operationId']);
    }

    public function test_head_is_not_documented(): void
    {
        $doc = $this->generator()->generate($this->router()->routes(), []);
        self::assertArrayNotHasKey('head', $doc['paths']['/api/users']);
    }

    public function test_exclusion_and_self_path(): void
    {
        $doc = $this->generator()->generate($this->router()->routes(), [], [
            'exclude' => fn ($r) => $r->path() === '/health',
        ]);
        self::assertArrayNotHasKey('/health', $doc['paths']);
    }

    public function test_orphan_description_is_an_error(): void
    {
        $g = $this->generator();
        $g->generate($this->router()->routes(), ['no.such.route' => ['summary' => 'x']]);
        self::assertTrue($g->hasErrors());
        self::assertStringContainsString('unknown route', $g->diagnostics()[0]['message']);
    }

    public function test_strict_mode_throws_on_errors(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->generator()->generate($this->router()->routes(), ['nope' => []], ['strict' => true]);
    }

    /* ---------- security ---------- */

    public function test_auth_api_middleware_marks_security(): void
    {
        $doc = $this->generator()->generate($this->router()->routes(), []);
        self::assertSame([['bearerAuth' => []]], $doc['paths']['/api/users']['get']['security']);
        self::assertSame([], $doc['paths']['/health']['get']['security']);
        self::assertSame(['users:read'], $doc['paths']['/api/users/{id}']['get']['x-token-abilities']);
        self::assertArrayHasKey('401', $doc['paths']['/api/users']['get']['responses']);
        self::assertArrayHasKey('403', $doc['paths']['/api/users']['get']['responses']);
        self::assertSame('bearer', $doc['components']['securitySchemes']['bearerAuth']['scheme']);
    }

    public function test_security_override_to_public(): void
    {
        $doc = $this->generator()->generate($this->router()->routes(), [
            'api.users' => ['security' => null, 'summary' => 'open list'],
        ]);
        self::assertSame([], $doc['paths']['/api/users']['get']['security']);
    }

    /* ---------- schema mapping ---------- */

    public function test_validation_rules_map_to_schema(): void
    {
        $schema = SchemaMapper::object([
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|email',
            'age' => 'nullable|integer|min:18',
            'role' => 'string|in:admin,user',
            'tags' => ['array'],
            'uuid' => 'uuid',
            'site' => 'url',
            'pin' => 'digits:4',
            'code' => 'string|regex:/^a\|b$/',
            'tier' => 'string|not_in:banned,blocked',
        ]);
        self::assertSame(['name', 'email'], $schema['required']);
        self::assertSame('email', $schema['properties']['email']['format']);
        self::assertSame(2, $schema['properties']['name']['minLength']);
        self::assertSame(['integer', 'null'], $schema['properties']['age']['type']);
        self::assertSame(18, $schema['properties']['age']['minimum']);
        self::assertSame(['admin', 'user'], $schema['properties']['role']['enum']);
        self::assertSame('uuid', $schema['properties']['uuid']['format']);
        self::assertSame('^\d{4}$', $schema['properties']['pin']['pattern']);
        // regex survives its literal pipe; not_in maps to `not`, never enum.
        self::assertSame('/^a|b$/', $schema['properties']['code']['pattern']);
        self::assertArrayNotHasKey('enum', $schema['properties']['tier']);
        self::assertSame(['banned', 'blocked'], $schema['properties']['tier']['not']['enum']);
    }

    public function test_unsupported_rule_is_diagnostic_not_false_claim(): void
    {
        $warnings = [];
        $schema = SchemaMapper::field('required|password', function ($l, $m) use (&$warnings) {
            $warnings[] = $m;
        });
        self::assertSame('password', $schema['schema']['x-validation-rule']);
        self::assertNotEmpty($warnings);
    }

    public function test_rules_meta_becomes_json_request_body(): void
    {
        $doc = $this->generator()->generate($this->router()->routes(), [
            'api.users.store' => [
                'summary' => 'Create user',
                'rules' => ['name' => 'required|string', 'email' => 'required|email'],
            ],
        ]);
        $body = $doc['paths']['/api/users']['post']['requestBody'];
        self::assertSame('object', $body['content']['application/json']['schema']['type']);
        self::assertSame(['name', 'email'], $body['content']['application/json']['schema']['required']);
    }

    /* ---------- apiQuery params ---------- */

    public function test_apiquery_descriptor_generates_listing_params(): void
    {
        $doc = $this->generator()->generate($this->router()->routes(), [
            'api.users' => ['apiQuery' => [
                'filters' => ['status', 'role'], 'sorts' => ['name'],
                'searchable' => ['name', 'email'], 'perPage' => 15, 'maxPerPage' => 100,
            ]],
        ]);
        $names = array_column($doc['paths']['/api/users']['get']['parameters'], 'name');
        self::assertContains('page', $names);
        self::assertContains('perPage', $names);
        self::assertContains('filter[status]', $names);
        self::assertContains('sort', $names);
        self::assertContains('search', $names);
    }

    /* ---------- resources ---------- */

    public function test_resource_schema_via_apischema(): void
    {
        $doc = $this->generator()->generate($this->router()->routes(), [
            'api.users.show' => ['responses' => [200 => ['resource' => DocUserResource::class]]],
        ]);
        $schema = $doc['paths']['/api/users/{id}']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame('integer', $schema['properties']['id']['type']);
    }

    public function test_resource_without_apischema_warns_not_guesses(): void
    {
        $g = $this->generator();
        $doc = $g->generate($this->router()->routes(), [
            'api.users.show' => ['responses' => [200 => UndocResource::class]],
        ]);
        self::assertSame('object', $doc['paths']['/api/users/{id}']['get']['responses']['200']['content']['application/json']['schema']['type']);
        self::assertSame(1, count(array_filter($g->diagnostics(), fn ($d) => str_contains($d['message'], 'apiSchema'))));
    }

    public function test_paginated_collection_envelope(): void
    {
        $doc = $this->generator()->generate($this->router()->routes(), [
            'api.users' => ['responses' => [200 => [
                'resource' => DocUserResource::class, 'collection' => true, 'paginated' => true,
            ]]],
        ]);
        $schema = $doc['paths']['/api/users']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame('array', $schema['properties']['data']['type']);
        self::assertSame('#/components/schemas/PaginationMeta', $schema['properties']['meta']['$ref']);
    }

    /* ---------- facade + output ---------- */

    public function test_facade_generates_json_and_diagnostics(): void
    {
        OpenApi::flushState();
        OpenApi::configure(['title' => 'Test API', 'version' => '2.0', 'servers' => ['https://api.test']]);
        OpenApi::describe('api.users', ['summary' => 'List', 'tags' => ['Users']]);

        $doc = OpenApi::generate($this->router());
        self::assertSame('Test API', $doc['info']['title']);
        self::assertSame([['url' => 'https://api.test']], $doc['servers']);
        self::assertSame('List', $doc['paths']['/api/users']['get']['summary']);
        self::assertContains('Users', array_column($doc['tags'] ?? [], 'name'));
        self::assertNotFalse(json_encode($doc));

        $res = OpenApi::response($this->router());
        self::assertSame('application/json', $res->header('Content-Type'));
        OpenApi::flushState();
    }

    public function test_ui_is_self_contained_and_points_at_spec(): void
    {
        $res = OpenApi::ui('/docs/openapi.json');
        self::assertSame('text/html; charset=utf-8', $res->header('Content-Type'));
        self::assertStringContainsString('/docs/openapi.json', $res->body());
        self::assertStringNotContainsString('cdn.', $res->body());
        self::assertStringNotContainsString('unpkg', $res->body());
    }

    public function test_explicit_swagger_ui_url_is_opt_in(): void
    {
        $res = OpenApi::ui('/spec.json', 'https://cdn.example/swagger-ui');
        self::assertStringContainsString('swagger-ui-bundle.js', $res->body());
    }

    public function test_every_ref_resolves_to_a_declared_component(): void
    {
        $doc = $this->generator()->generate($this->router()->routes(), [
            'api.users' => ['responses' => [200 => [
                'resource' => DocUserResource::class, 'collection' => true, 'paginated' => true,
            ]]],
        ]);

        self::assertArrayHasKey('PaginationMeta', $doc['components']['schemas'] ?? []);

        $refs = [];
        $walk = static function (mixed $node) use (&$walk, &$refs): void {
            if (!is_array($node)) {
                return;
            }
            if (isset($node['$ref'])) {
                $refs[] = $node['$ref'];
            }
            foreach ($node as $v) {
                $walk($v);
            }
        };
        $walk($doc);

        foreach ($refs as $ref) {
            self::assertStringStartsWith('#/components/schemas/', $ref);
            $name = substr($ref, strlen('#/components/schemas/'));
            self::assertArrayHasKey($name, $doc['components']['schemas'], "Dangling \$ref [$ref]");
        }
    }
}
