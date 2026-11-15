<?php

declare(strict_types=1);

namespace Sahil\OpenApi;

use Sahil\Http\Resources\Resource;
use Sahil\Routing\Link;
use Sahil\Routing\Version;

/**
 * Builds an OpenAPI 3.1 document from the REGISTERED route table —
 * `Router::routes()` is the single source of truth. No controller is
 * executed, no request handled, no middleware instantiated.
 *
 * What can be inferred is inferred (methods, paths, path params,
 * middleware-driven security); what cannot is declared via
 * {@see OpenApi::describe()} or `Link`-level metadata — never guessed.
 */
final class Generator
{
    /** @var list<array{level: string, message: string}> */
    private array $diagnostics = [];

    /** Component schemas actually referenced by generated operations. */
    private bool $paginationMetaUsed = false;

    /** @var array<string, true> security scheme names referenced by operations */
    private array $usedSchemes = [];

    /**
     * @param iterable<Link> $routes registered routes
     * @param array<string, array<string, mixed>> $descriptions keyed by
     *        route name OR "METHOD /path"
     * @param array<string, mixed> $config
     */
    public function generate(iterable $routes, array $descriptions = [], array $config = []): array
    {
        $this->diagnostics = [];
        $this->paginationMetaUsed = false;
        $this->usedSchemes = [];

        $paths = [];
        $tags = [];
        $claimed = [];
        $knownNames = [];
        $knownVersions = [];

        // Materialize once — route metadata drives both filtering and the
        // orphan-metadata check below.
        $routes = is_array($routes) ? $routes : iterator_to_array($routes);

        // Version scoping: `api_version => 'v1'` documents only routes
        // tagged v1; `include_unversioned => true` keeps untagged routes
        // too. (`version` stays reserved for info.version.)
        $version = isset($config['api_version'])
            ? Version::normalize((string) $config['api_version'])
            : null;
        $includeUnversioned = (bool) ($config['include_unversioned'] ?? false);

        foreach ($routes as $route) {
            if (!$route instanceof Link) {
                continue;
            }
            if ($route->routeName() !== null) {
                $knownNames[$route->routeName()] = true;
            }
            foreach ($route->methods() as $m) {
                $knownNames[$m.' '.$route->path()] = true;
            }
            if ($route->apiVersion() !== null) {
                $knownVersions[$route->apiVersion()] = true;
            }
        }

        if ($version !== null && !isset($knownVersions[$version])) {
            $this->diagnose('error', sprintf(
                'Requested API version [%s] is not registered; known versions: [%s].',
                $version,
                implode(', ', array_keys($knownVersions)) ?: 'none',
            ));
        }

        $include = $config['include'] ?? static fn (Link $r): bool => true;
        $exclude = $config['exclude'] ?? static fn (Link $r): bool => false;
        $selfPaths = array_map('strval', (array) ($config['self_paths'] ?? []));

        foreach ($routes as $route) {
            if (!$route instanceof Link || !$include($route) || $exclude($route)) {
                continue;
            }

            if (in_array($route->path(), $selfPaths, true)) {
                continue; // the docs route itself stays out unless asked for
            }

            if ($version !== null) {
                $routeVersion = $route->apiVersion();
                if ($routeVersion === null ? !$includeUnversioned : $routeVersion !== $version) {
                    continue;
                }
            }

            $key = $route->routeName() ?? null;
            $meta = [];

            if ($key !== null && isset($descriptions[$key])) {
                $meta = $descriptions[$key];
                $claimed[$key] = true;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }

                $opKey = $method.' '.$route->path();

                if (isset($descriptions[$opKey])) {
                    $meta = [...$meta, ...$descriptions[$opKey]];
                    $claimed[$opKey] = true;
                }

                $paths[$route->path()][strtolower($method)] = $this->operation($route, $method, $meta, $config);

                foreach ((array) ($meta['tags'] ?? []) as $tag) {
                    $tags[$tag] = true;
                }
            }
        }

        // Orphan metadata: described something that isn't a real route.
        // Routes filtered out by version scoping still count as existing —
        // their metadata belongs to another version's document.
        foreach ($descriptions as $ref => $_) {
            if (!isset($claimed[$ref]) && !isset($knownNames[$ref])) {
                $this->diagnose('error', "describe() references unknown route [$ref] — no matching registered route.");
            }
        }

        ksort($paths);

        $document = array_filter([
            'openapi' => '3.1.0',
            'x-api-version' => $version,
            'info' => array_filter([
                'title' => $config['title'] ?? 'SahilPHP API',
                'description' => $config['description'] ?? null,
                // A version-scoped document identifies the API version as
                // its own version — that is the field consumers read.
                'version' => $version ?? $config['version'] ?? '1.0.0',
                'license' => isset($config['license'])
                    ? array_filter(['name' => $config['license'], 'url' => $config['license_url'] ?? null])
                    : null,
            ]),
            'servers' => array_map(
                static fn (string $url) => ['url' => $url],
                (array) ($config['servers'] ?? []),
            ) ?: null,
            'paths' => $paths,
            'components' => array_filter([
                'securitySchemes' => $this->securitySchemes($config),
                'schemas' => $this->componentSchemas(),
            ]),
            'tags' => array_map(
                static function (string $name) use ($config): array {
                    return array_filter([
                        'name' => $name,
                        'description' => $config['tag_descriptions'][$name] ?? null,
                    ]);
                },
                array_keys($tags),
            ) ?: null,
            'externalDocs' => isset($config['externalDocs'])
                ? ['url' => $config['externalDocs']] : null,
        ], static fn ($v) => $v !== null && $v !== []);

        if (($config['strict'] ?? false) && $this->hasErrors()) {
            throw new \RuntimeException(sprintf(
                'OpenAPI generation failed in strict mode: %s',
                implode('; ', array_map(
                    static fn (array $d) => $d['message'],
                    array_filter($this->diagnostics, static fn (array $d) => $d['level'] === 'error'),
                )),
            ));
        }

        return $document;
    }

    /** @return list<array{level: string, message: string}> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $d) {
            if ($d['level'] === 'error') {
                return true;
            }
        }

        return false;
    }

    private function diagnose(string $level, string $message): void
    {
        $this->diagnostics[] = ['level' => $level, 'message' => $message];
    }

    /* ---------- operation ---------- */

    private function operation(Link $route, string $method, array $meta, array $config): array
    {
        $parameters = [];

        // Path params from the registered pattern — always required.
        if (preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)(\?)?\}/', $route->path(), $m)) {
            foreach ($m[1] as $i => $name) {
                $declared = (array) ($meta['pathParams'][$name] ?? []);
                $parameters[] = [
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'schema' => $declared['schema'] ?? ['type' => 'string'],
                    ...array_filter(['description' => $declared['description'] ?? null]),
                ];
            }
        }

        // Declared query parameters.
        foreach ((array) ($meta['query'] ?? []) as $name => $param) {
            $parameters[] = $this->parameter($name, 'query', $param);
        }

        // ApiQuery descriptor → the listing params it enables.
        if (isset($meta['apiQuery'])) {
            foreach ($this->apiQueryParameters((array) $meta['apiQuery']) as $p) {
                $parameters[] = $p;
            }
        }

        $requestBody = null;
        if (isset($meta['rules'])) {
            $requestBody = [
                'required' => true,
                'content' => [$meta['contentType'] ?? 'application/json' => [
                    'schema' => SchemaMapper::object(
                        (array) $meta['rules'],
                        fn (string $lvl, string $msg) => $this->diagnose($lvl, $msg),
                    ),
                ]],
            ];
        } elseif (isset($meta['requestBody'])) {
            $requestBody = ['required' => true, 'content' => [
                $meta['contentType'] ?? 'application/json' => ['schema' => $meta['requestBody']],
            ]];
        }

        $responses = [];
        foreach ((array) ($meta['responses'] ?? [200 => null]) as $status => $def) {
            $responses[(string) $status] = $this->response((int) $status, $def, $meta);
        }

        // Security from REAL route middleware — never guessed. Public
        // operations carry an explicit `security: []` so validators and
        // readers can tell "declared public" from "forgotten".
        $security = [];
        $middleware = array_map(
            static fn ($m) => is_string($m) ? $m : (is_object($m) ? $m::class : ''),
            $route->middlewareList(),
        );

        foreach ($middleware as $mw) {
            if ($mw === 'auth:api' || str_starts_with($mw, 'auth:api')) {
                $security = [['bearerAuth' => []]];
            }

            if (str_starts_with((string) $mw, 'token:')) {
                $meta['x-token-abilities'] = explode(',', substr((string) $mw, 6));
            }
        }

        if (array_key_exists('security', $meta)) {
            $security = $meta['security'] ?? []; // explicit override wins ('[]'/null = public)
        }

        if ($security !== []) {
            foreach ($security as $requirement) {
                foreach (array_keys((array) $requirement) as $scheme) {
                    $this->usedSchemes[$scheme] = true;
                }
            }
            $responses += [
                '401' => ['description' => 'Unauthenticated'],
                '403' => ['description' => 'Forbidden — token ability or permission denied'],
            ];
        }

        // Validated inputs really do return the native 422 body.
        if ((isset($meta['rules']) || isset($meta['apiQuery']))
            && !isset($meta['responses'][422]) && !isset($meta['responses']['422'])) {
            $responses['422'] = [
                'description' => 'Validation failed',
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string'],
                        'errors' => [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ]]],
            ];
        }

        ksort($responses);

        return array_filter([
            'summary' => $meta['summary'] ?? null,
            'description' => $meta['description'] ?? null,
            'operationId' => $meta['operationId'] ?? ($route->routeName() !== null
                ? strtr($route->routeName(), '.-', '__') : null),
            'tags' => (array) ($meta['tags'] ?? []) ?: null,
            'deprecated' => ($meta['deprecated'] ?? false) ?: null,
            'parameters' => $parameters === [] ? null : $parameters,
            'requestBody' => $requestBody,
            'responses' => $responses,
            'security' => $security,
            'x-token-abilities' => $meta['x-token-abilities'] ?? null,
            'x-controller' => $this->controllerName($route) && ($config['expose_handlers'] ?? false)
                ? $this->controllerName($route) : null,
        ], static fn ($v) => $v !== null);
    }

    private function controllerName(Link $route): ?string
    {
        $handler = $route->handler();

        return is_array($handler) ? $handler[0].'@'.$handler[1] : null;
    }

    private function parameter(string $name, string $in, mixed $param): array
    {
        if (is_string($param)) {
            $param = ['schema' => ['type' => 'string'], 'description' => $param];
        } elseif (!is_array($param)) {
            $param = [];
        }

        return [
            'name' => $name,
            'in' => $in,
            'required' => $param['required'] ?? false,
            'schema' => $param['schema'] ?? ['type' => 'string'],
            ...array_filter([
                'description' => $param['description'] ?? null,
                'example' => $param['example'] ?? null,
            ]),
        ];
    }

    /** Translate an ApiQuery declaration into the params it enables. */
    private function apiQueryParameters(array $apiQuery): array
    {
        $out = [
            ['name' => 'page', 'in' => 'query', 'required' => false,
             'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            ['name' => 'perPage', 'in' => 'query', 'required' => false,
             'schema' => ['type' => 'integer', 'minimum' => 1,
                'maximum' => $apiQuery['maxPerPage'] ?? 100, 'default' => $apiQuery['perPage'] ?? 15]],
        ];

        foreach ((array) ($apiQuery['filters'] ?? []) as $f) {
            $out[] = ['name' => "filter[$f]", 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'string']];
        }

        if (($apiQuery['sorts'] ?? []) !== []) {
            $out[] = ['name' => 'sort', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'string', 'enum' => array_merge(
                    (array) $apiQuery['sorts'],
                    array_map(static fn ($c) => '-'.$c, (array) $apiQuery['sorts']),
                )]];
        }

        if (($apiQuery['searchable'] ?? []) !== []) {
            $out[] = ['name' => 'search', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'string', 'maxLength' => 200],
                'description' => 'Substring search across: '.implode(', ', (array) $apiQuery['searchable'])];
        }

        if (($apiQuery['fields'] ?? []) !== []) {
            $out[] = ['name' => 'fields', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'string'],
                'description' => 'Comma-separated subset of: '.implode(', ', (array) $apiQuery['fields'])];
        }

        return $out;
    }

    private function response(int $status, mixed $def, array $meta): array
    {
        if ($def === null) {
            return ['description' => $status === 204 ? 'No content' : 'Successful response'];
        }

        // `200 => UserResource::class` or `['resource'=>X,'collection'=>true,'paginated'=>true]`
        if (is_string($def) && is_subclass_of($def, Resource::class)) {
            $def = ['resource' => $def];
        }

        if (!is_array($def)) {
            return ['description' => 'Successful response'];
        }

        $schema = null;

        if (isset($def['resource'])) {
            $schema = $this->resourceSchema($def['resource'], $def);
        } elseif (isset($def['schema'])) {
            $schema = $def['schema'];
        } elseif (isset($def['rules'])) {
            $schema = SchemaMapper::object(
                (array) $def['rules'],
                fn (string $l, string $m) => $this->diagnose($l, $m),
            );
        }

        return array_filter([
            'description' => $def['description'] ?? ($status === 201 ? 'Created' : 'Successful response'),
            'content' => $schema !== null ? ['application/json' => ['schema' => $schema]] : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * A resource's wire schema comes ONLY from its own static
     * `apiSchema()` declaration — `toArray()` is code; we never execute
     * it or instantiate models to guess.
     */
    private function resourceSchema(string $class, array $def): array
    {
        if (!method_exists($class, 'apiSchema') || ($schema = $class::apiSchema()) === null) {
            $this->diagnose('warning', sprintf(
                'Resource [%s] declares no static apiSchema(); emitting a generic object.',
                $class,
            ));
            $schema = ['type' => 'object'];
        }

        if ($def['collection'] ?? false) {
            $schema = ['type' => 'array', 'items' => $schema];
        }

        if ($def['paginated'] ?? false) {
            $this->paginationMetaUsed = true;
            $item = $schema;
            $schema = [
                'type' => 'object',
                'properties' => [
                    'data' => ($def['collection'] ?? false) ? $item : ['type' => 'array', 'items' => $item],
                    'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                ],
            ];
        }

        return $schema;
    }

    /**
     * Security schemes actually referenced by operations — `bearerAuth`
     * is always available, app-declared `security_schemes` are emitted
     * when used.
     */
    private function securitySchemes(array $config): ?array
    {
        $schemes = [];
        if (isset($this->usedSchemes['bearerAuth'])) {
            $schemes['bearerAuth'] = ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'opaque'];
        }
        foreach ((array) ($config['security_schemes'] ?? []) as $name => $def) {
            if (isset($this->usedSchemes[$name])) {
                $schemes[$name] = $def;
            } else {
                $this->diagnose('warning', "security_schemes declares [$name] but no operation references it.");
            }
        }
        foreach (array_keys($this->usedSchemes) as $name) {
            if (!isset($schemes[$name])) {
                $this->diagnose('error', "Operation security references undeclared scheme [$name].");
            }
        }

        return $schemes === [] ? null : $schemes;
    }

    /**
     * Reusable component schemas — only those actually `$ref`'d by
     * generated operations, so every reference resolves.
     *
     * @return array<string, array<string, mixed>>
     */
    private function componentSchemas(): array
    {
        if (!$this->paginationMetaUsed) {
            return [];
        }

        // Mirrors Sahil\Http\Pagination\Paginator::toArray() exactly.
        return [
            'PaginationMeta' => [
                'type' => 'object',
                'properties' => [
                    'total' => ['type' => 'integer'],
                    'perPage' => ['type' => 'integer'],
                    'currentPage' => ['type' => 'integer'],
                    'lastPage' => ['type' => 'integer'],
                    'hasMorePages' => ['type' => 'boolean'],
                    'nextPageUrl' => ['type' => ['string', 'null']],
                    'prevPageUrl' => ['type' => ['string', 'null']],
                ],
                'required' => ['total', 'perPage', 'currentPage', 'lastPage', 'hasMorePages'],
            ],
        ];
    }
}
