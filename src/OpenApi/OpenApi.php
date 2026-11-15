<?php

declare(strict_types=1);

namespace Sahil\OpenApi;

use Sahil\Http\Response;
use Sahil\Http\Router;

/**
 * Native OpenAPI 3.1 generation — the registered `Router` is the source
 * of truth; `describe()` adds only what can't be inferred.
 *
 *     OpenApi::describe('api.users', [
 *         'summary' => 'List users',
 *         'tags' => ['Users'],
 *         'apiQuery' => ['filters' => ['status'], 'sorts' => ['name'], 'searchable' => ['name']],
 *         'responses' => [200 => ['resource' => UserResource::class, 'collection' => true, 'paginated' => true]],
 *     ]);
 *
 *     Link::get('/docs/openapi.json', fn () => OpenApi::response($router));
 */
final class OpenApi
{
    /** @var array<string, array<string, mixed>> */
    private static array $descriptions = [];

    /** @var array<string, mixed> */
    private static array $config = [];

    private static ?Generator $lastGenerator = null;

    /** Declare metadata for a route — by name, or "METHOD /path". */
    public static function describe(string $route, array $metadata): void
    {
        self::$descriptions[$route] = $metadata;
    }

    /** @param array<string, mixed> $config title/version/servers/include/exclude/strict */
    public static function configure(array $config): void
    {
        self::$config = [...self::$config, ...$config];
    }

    /** Generate the document — routes are enumerated, never executed. */
    public static function generate(Router $router, array $config = []): array
    {
        $generator = new Generator();
        self::$lastGenerator = $generator;

        return $generator->generate($router->routes(), self::$descriptions, [...self::$config, ...$config]);
    }

    /** The generated document as a JSON `Response`. */
    public static function response(Router $router, array $config = []): Response
    {
        return Response::json(self::generate($router, $config));
    }

    /** Diagnostics from the last generation (warnings + errors). */
    public static function diagnostics(): array
    {
        return self::$lastGenerator?->diagnostics() ?? [];
    }

    /**
     * Self-contained documentation page (zero CDN dependency): a small
     * vanilla-JS viewer that renders the generated spec. Applications
     * wanting full Swagger UI can set `ui_library_url` and get the real
     * thing — the opt-in keeps CDN use explicit.
     */
    public static function ui(string $specUrl = '/docs/openapi.json', ?string $uiLibraryUrl = null): Response
    {
        $library = ($uiLibraryUrl ?? self::$config['ui_library_url'] ?? null) ?: null;
        $body = $library !== null
            ? self::swaggerHtml($specUrl, (string) $library)
            : self::nativeHtml($specUrl);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    /** Reset descriptions/config/diagnostics — per-test isolation. */
    public static function flushState(): void
    {
        self::$descriptions = [];
        self::$config = [];
        self::$lastGenerator = null;
    }

    private static function nativeHtml(string $specUrl): string
    {
        $url = htmlspecialchars($specUrl, ENT_QUOTES);

        return <<<HTML
        <!doctype html><html><head><meta charset="utf-8"><title>API Documentation</title>
        <style>body{font:14px/1.5 system-ui,sans-serif;max-width:960px;margin:2rem auto;padding:0 1rem}
        .op{border:1px solid #ddd;border-radius:8px;margin:.75rem 0;padding:.75rem 1rem}
        .m{display:inline-block;min-width:4.5em;font-weight:700;text-transform:uppercase}
        .GET{color:#0a7}.POST{color:#06c}.PUT,.PATCH{color:#a60}.DELETE{color:#c33}
        code{background:#f4f4f4;padding:0 .3em;border-radius:4px}
        details{margin:.5rem 0}</style></head><body>
        <h1 id="t">API Documentation</h1><div id="d">Loading…</div>
        <script>
        fetch({js:$url}).then(r=>r.json()).then(s=>{
          document.getElementById('t').textContent=(s.info&&s.info.title)||'API Documentation';
          let h='';
          for(const [p,ops] of Object.entries(s.paths||{}))for(const[m,o]of Object.entries(ops)){
            h+=`<div class="op"><span class="m \${m.toUpperCase()}">\${m.toUpperCase()}</span> <code>\${p}</code>`;
            if(o.summary)h+=` — \${o.summary}`;
            if(o.security)h+=' 🔒';
            if(o.parameters)h+=`<details><summary>Parameters</summary><ul>\${o.parameters.map(x=>`<li><code>\${x.name}</code> (\${x.in})\${x.required?' <b>required</b>':''}</li>`).join('')}</ul></details>`;
            if(o.requestBody)h+='<details><summary>Request body</summary><pre>'+JSON.stringify(o.requestBody.content['application/json'].schema,null,2)+'</pre></details>';
            h+=`<details><summary>Responses</summary><ul>\${Object.keys(o.responses||{}).map(c=>`<li><code>\${c}</code></li>`).join('')}</ul></details></div>`;}
          document.getElementById('d').innerHTML=h||'<p>No operations documented.</p>';
        }).catch(e=>{document.getElementById('d').textContent='Failed to load spec: '+e});
        </script></body></html>
        HTML;
    }

    private static function swaggerHtml(string $specUrl, string $uiLibraryUrl): string
    {
        $url = htmlspecialchars($specUrl, ENT_QUOTES);
        $lib = htmlspecialchars($uiLibraryUrl, ENT_QUOTES);

        return <<<HTML
        <!doctype html><html><head><meta charset="utf-8"><title>API Documentation</title></head><body>
        <div id="ui"></div>
        <script src="{$lib}/swagger-ui-bundle.js"></script>
        <script src="{$lib}/swagger-ui-standalone-preset.js"></script>
        <link rel="stylesheet" href="{$lib}/swagger-ui.css">
        <script>window.onload=()=>{SwaggerUIBundle({url:'{$url}',dom_id:'#ui',
          presets:[SwaggerUIBundle.presets.apis,SwaggerUIStandalonePreset],layout:'StandaloneLayout'})};</script>
        </body></html>
        HTML;
    }
}
