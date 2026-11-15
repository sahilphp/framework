<?php

declare(strict_types=1);

namespace Sahil\Http;

use Sahil\Http\Exception\BadRequestHttpException;
use Sahil\Http\Exception\MethodNotAllowedHttpException;
use Sahil\Validation\FormInput;
use Sahil\Validation\ValidationException;
use Sahil\Validation\Validator;

/**
 * Immutable, validated HTTP request representation.
 *
 * This class is the *only* safe window into the PHP request superglobals:
 * every consumer reads request data through this abstraction and nothing is
 * ever written back to the superglobals. `fromGlobals()` snapshots the state
 * once, so the object cannot drift after construction.
 *
 * Security posture:
 * - `clientIp()` returns `REMOTE_ADDR` only. Forwarded headers
 *   (`X-Forwarded-For`, `X-Real-IP`, ...) are deliberately *ignored*
 *   until trusted-proxy support lands in a later phase.
 * - Scheme detection uses `HTTPS` / `REQUEST_SCHEME` server variables only.
 * - Body access is explicit (`body()`, `json()`, `form()`), so consumers
 *   cannot confuse query and body input by accident.
 * - Unsupported methods throw `MethodNotAllowedHttpException` right away.
 */
final class Request
{
    /** Methods the HTTP entry point currently supports. */
    public const SUPPORTED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    private const METHOD_PATTERN = '/^[A-Z]{1,20}$/';

    /**
     * @param array<string, string> $query
     * @param array<string, string> $cookies
     * @param array<string, string> $form
     * @param array<string, string> $headers
     */
    private function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly string $path,
        private readonly string $queryString,
        private readonly array $query,
        private readonly array $cookies,
        private readonly array $headers,
        private readonly string $body,
        private readonly ?string $clientIp,
        private readonly ?string $scheme,
        private readonly ?string $contentType,
        private readonly array $form,
        private readonly array $files = [],
    ) {
    }

    /**
     * Build from the PHP request superglobals. This is the single boundary:
     * `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE` and `php://input` are read
     * here and nowhere else, and never mutated.
     *
     * Passing `null` (the default) for any input means "read the live
     * superglobal"; passing an array (even an empty one) means "ignore the
     * superglobal entirely and use exactly this", which is what the tests
     * use.
     *
     * @param array<string, mixed> $server   replaces $_SERVER (test override)
     * @param array<string, mixed> $query    replaces $_GET (test override)
     * @param array<string, string> $cookies replaces $_COOKIE (test override)
     * @param array<string, string> $form    replaces $_POST (test override)
     * @param array<string, mixed> $files    replaces $_FILES (test override)
     */
    public static function fromGlobals(
        ?array $server = null,
        ?array $query = null,
        ?array $cookies = null,
        ?array $form = null,
        ?string $body = null,
        ?array $files = null,
    ): self {
        $server ??= $_SERVER;
        $query ??= $_GET;
        $cookies ??= $_COOKIE;
        $form ??= $_POST;
        $body ??= self::rawBody();
        $files ??= $_FILES;

        return self::build(
            method: self::extractMethod($server),
            server: $server,
            query: self::queryMap($query),
            cookies: self::stringMap($cookies, 'COOKIE'),
            form: self::stringMap($form, 'POST'),
            body: $body,
            files: $files,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isHead(): bool
    {
        return $this->method === 'HEAD';
    }

    public function methodIs(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * Raw request target exactly as received (never decoded here).
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Percent-decoded path component without the query string.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The original query string, unparsed.
     */
    public function queryString(): string
    {
        return $this->queryString;
    }

    /**
     * All parsed query parameters. Scalars stay strings; nested
     * `?filter[x]=y` structures survive as arrays of scalars.
     *
     * @return array<string, string|array>
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * One query parameter or a default.
     */
    public function queryParameter(string $name, ?string $default = null): ?string
    {
        return $this->query[$name] ?? $default;
    }

    public function hasQueryParameter(string $name): bool
    {
        return array_key_exists($name, $this->query);
    }

    /**
     * Sanitized request cookies: string-keyed, string-only values.
     */
    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Uploaded files as `name → UploadedFile` (or `name → list<UploadedFile>`
     * for `photos[]`-style inputs). Failed/missing uploads still surface as
     * objects — `isValid()` reports their state to validation rules.
     *
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * One uploaded file by input name (first entry for array inputs).
     */
    public function file(string $name): ?UploadedFile
    {
        $file = $this->files[$name] ?? null;

        if (is_array($file)) {
            $file = $file[array_key_first($file)] ?? null;
        }

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * Request headers as a `name → comma-joined values` map, keys in
     * `Canonical-Header-Form`.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Header by case-insensitive name, comma-joined, or a default.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $needle = strtolower($name);

        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $needle) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * A client-supplied idempotency token, or null when absent.
     *
     * The same token on an idempotent method (POST/PUT/PATCH/DELETE) must
     * reuse the previous response: do not re-execute the operation.
     * Never trusted for authorization—only for safe request replay.
     */
    public function idempotencyKey(): ?string
    {
        return $this->header('Idempotency-Key');
    }

    public function hasHeader(string $name): bool
    {
        return $this->header($name) !== null;
    }

    /**
     * Raw body bytes.
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Content-Type as received (full value including parameters).
     */
    public function contentType(): ?string
    {
        return $this->contentType;
    }

    /**
     * Content-Type without parameters, lower-cased (`multipart/form-data; boundary=…`
     * normalizes to `multipart/form-data`). Null when the header is missing or empty.
     *
     * Comparisons against declared media types must use this instead of
     * contentType(): real multipart and charset-qualified requests always
     * carry parameters.
     */
    public function mediaType(): ?string
    {
        return self::normalizeMediaType($this->contentType);
    }

    /**
     * Whether the body is declared JSON (`application/json`, ignoring
     * charset parameters).
     */
    public function hasJsonBody(): bool
    {
        return self::normalizeMediaType($this->contentType) === 'application/json';
    }

    /**
     * Whether the body is declared form-encoded.
     */
    public function hasFormBody(): bool
    {
        $media = self::normalizeMediaType($this->contentType);

        return $media === 'application/x-www-form-urlencoded' || $media === 'multipart/form-data';
    }

    /**
     * Parse the body as JSON:
     * - an empty body is an empty array;
     * - a valid JSON object or array decodes to an array;
     * - anything else (scalars, malformed JSON) throws a
     *   BadRequestHttpException carrying the parse failure.
     *
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        $raw = trim($this->body);

        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $exception) {
            throw BadRequestHttpException::malformedJson($exception);
        }

        if (!is_array($decoded)) {
            throw new BadRequestHttpException(
                'JSON request body must decode to an object or an array, not a scalar value.',
            );
        }

        return $decoded;
    }

    /**
     * Sanitized form fields: a string-keyed, string-only map. The returned
     * {@see Form} reads exactly like the array it is — `isset`, `foreach`,
     * spread and `json_encode` keep working — and adds `->validate()`.
     */
    public function form(): Form
    {
        return new Form($this->form);
    }

    /**
     * Validate this request's merged input — query parameters plus the
     * body fields (JSON object or form map, body winning collisions) —
     * and return only the validated data.
     *
     *     $data = $request->validate([
     *         'email'    => 'required|email',
     *         'password' => 'required|string|password|confirmed',
     *     ]);
     *
     * On failure a {@see ValidationException} is thrown; the HTTP kernel
     * answers JSON clients with 422 and redirects browsers back with the
     * errors flashed into `$bag` (default bag when null) plus sanitized
     * old input.
     *
     * @param array<string, string|array<int, mixed>> $rules
     * @param array<string, string> $messages custom messages
     *     (`field.rule` → `field` → bare `rule`)
     * @param array<int, string>|string $normalize opt-in normalization (`trim`)
     *
     * @return array<string, mixed>
     */
    public function validate(
        array $rules,
        array $messages = [],
        ?string $bag = null,
        array|string $normalize = [],
    ): array {
        $input = FormInput::fromRequest($this);

        $validator = new Validator($rules, $messages);

        if ($normalize !== []) {
            $validator = $validator->normalize($normalize);
        }

        $result = $validator->validate($input);

        if ($result->fails()) {
            throw ValidationException::forResult($result, $bag, $input);
        }

        return $result->data();
    }

    /**
     * Immediate TCP peer (`REMOTE_ADDR`). Proxy headers are ignored on
     * purpose; trusted-proxy support is a configured, later-phase feature.
     */
    public function clientIp(): ?string
    {
        return $this->clientIp;
    }

    /**
     * "http" / "https", or null when undeterminable.
     */
    public function scheme(): ?string
    {
        return $this->scheme;
    }

    public function isSecure(): bool
    {
        return $this->scheme === 'https';
    }

    /**
     * Effective protocol/method mapping of HEAD: same headers as GET, no
     * body. Callers decide what "GET handling" means; the framework keeps
     * the method faithful to what was received.
     */
    public function methodForRouting(): string
    {
        return $this->method;
    }

    /**
    * @param array<array-key, mixed> $server
     */
    private static function extractMethod(array $server): string
    {
        $method = self::stringOrNull($server['REQUEST_METHOD'] ?? null);

        if ($method === null || preg_match(self::METHOD_PATTERN, $method) !== 1) {
            throw new BadRequestHttpException(
                sprintf(
                    'Rejecting malformed/duplicate request line: REQUEST_METHOD is %s; expected a Supported-Method token.',
                    $method === null ? 'absent' : var_export($method, true),
                ),
            );
        }

        if (!in_array($method, self::SUPPORTED_METHODS, true)) {
            throw new MethodNotAllowedHttpException(self::SUPPORTED_METHODS, sprintf('HTTP method [%s] is not supported.', $method));
        }

        return $method;
    }

    /**
     * Assemble the request object from already-validated parts.
     *
    * @param array<array-key, mixed> $server
     * @param array<string, string> $query
     * @param array<string, string> $cookies
     * @param array<string, string> $form
     */
    private static function build(
        string $method,
        array $server,
        array $query,
        array $cookies,
        array $form,
        string $body,
        array $files = [],
    ): self {
        $uri = self::stringOrNull($server['REQUEST_URI'] ?? null) ?? '/';

        if (preg_match('/^[\x21-\x7E\x80-\xFF]*$/', $uri) !== 1) {
            throw new BadRequestHttpException('Request target contains ASCII control characters.');
        }

        $path = self::pathFrom($uri);
        $queryString = self::stringOrNull($server['QUERY_STRING'] ?? null);
        $contentType = self::stringOrNull($server['CONTENT_TYPE'] ?? null);

        return new self(
            method: $method,
            uri: $uri,
            path: $path,
            queryString: $queryString ?? '',
            query: $query,
            cookies: $cookies,
            headers: self::headersFrom($server, $contentType),
            body: $body,
            clientIp: self::clientIpFrom($server),
            scheme: self::schemeFrom($server),
            contentType: $contentType,
            form: $form,
            files: self::uploadedFiles($files),
        );
    }

    /**
     * Normalise the `$_FILES` superglobal into `UploadedFile` objects.
     * Nested name arrays (`photos[]`) become `list<UploadedFile>` entries.
     *
     * @param array<string, mixed> $files
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    private static function uploadedFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (isset($entry['tmp_name']) && is_array($entry['tmp_name'])) {
                $list = [];

                foreach ($entry['tmp_name'] as $index => $_) {
                    $file = UploadedFile::fromFilesEntry([
                        'tmp_name' => $entry['tmp_name'][$index] ?? null,
                        'name' => $entry['name'][$index] ?? null,
                        'type' => $entry['type'][$index] ?? null,
                        'error' => $entry['error'][$index] ?? null,
                        'size' => $entry['size'][$index] ?? null,
                    ]);

                    if ($file !== null) {
                        $list[$index] = $file;
                    }
                }

                $normalized[(string) $key] = $list;

                continue;
            }

            $file = UploadedFile::fromFilesEntry($entry);

            if ($file !== null) {
                $normalized[(string) $key] = $file;
            }
        }

        return $normalized;
    }

    /**
     * @param array<array-key, scalar|list<scalar>> $server runtime superglobal state
     *
     * @return array<string, string>
     */
    private static function headersFrom(array $server, ?string $contentType): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $name = implode('-', array_map(ucfirst(...), explode('_', strtolower(substr($key, 5)))));
                $headers[$name] = $value;
            }
        }

        if (isset($server['CONTENT_TYPE']) && is_string($server['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $server['CONTENT_TYPE'];
        }

        if (isset($server['CONTENT_LENGTH']) && is_string($server['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $server['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * Percent-decode the request target; the original `%`-protected
     * encoding of slash (`%2F`) is tolerated (kept encoded) — routing
     * decides what to do with it later phases will explicitly state.
     */
    private static function pathFrom(string $uri): string
    {
        $withoutQuery = $uri;

        if (str_contains($withoutQuery, '?')) {
            $withoutQuery = substr($withoutQuery, 0, strpos($withoutQuery, '?'));
        }

        $isFragmentForbidden = str_contains($withoutQuery, '#');

        if ($isFragmentForbidden) {
            throw new BadRequestHttpException('Fragment markers are not valid in HTTP request targets.');
        }

        $decoded = rawurldecode($withoutQuery);

        if (str_contains($decoded, "\0")) {
            throw new BadRequestHttpException('Request path contains encoded NUL bytes.');
        }

        return $decoded;
    }

    /**
    * @param array<array-key, mixed> $server
     */
    private static function clientIpFrom(array $server): ?string
    {
        return self::stringOrNull($server['REMOTE_ADDR'] ?? null);
    }

    /**
    * @param array<array-key, mixed> $server
     */
    private static function schemeFrom(array $server): ?string
    {
        $https = self::stringOrNull($server['HTTPS'] ?? null);

        if ($https !== null && strtolower($https) !== '' && strtolower($https) !== 'off') {
            return 'https';
        }

        $scheme = self::stringOrNull($server['REQUEST_SCHEME'] ?? null);

        return match ($scheme) {
            'http' => 'http',
            'https' => 'https',
            default => null,
        };
    }

    private static function rawBody(): string
    {
        $raw = file_get_contents('php://input');

        return $raw === false ? '' : $raw;
    }

    /**
     * Coerce a superglobal map to string=>string only: entries with
     * non-string names or non-string values (e.g. nested query arrays)
     * are dropped. Cookie names, in particular, may legitimately be
     * numeric, so filtering is safer than rejecting whole requests.
     *
     * @param array<array-key, mixed> $source
     *
     * @return array<string, string>
     */
    private static function stringMap(array $source, string $context): array
    {
        $clean = [];

        foreach ($source as $key => $value) {
            if (is_string($key) && $key !== '' && is_string($value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Like {@see stringMap()} but preserves nested arrays of scalars so
     * `?filter[status]=active&filter[role][]=admin` keeps its structure —
     * non-scalar leaves are dropped.
     */
    private static function queryMap(array $source, int $depth = 0): array
    {
        $clean = [];

        foreach ($source as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_string($value)) {
                $clean[$key] = $value;
            } elseif (is_scalar($value)) {
                $clean[$key] = (string) $value;
            } elseif (is_array($value) && $depth < 4) {
                $clean[$key] = self::queryMap($value, $depth + 1);
            }
        }

        return $clean;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Media type without parameters, lower-cased; null when missing/odd.
     */
    private static function normalizeMediaType(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }

        $media = explode(';', strtolower($contentType))[0];

        $trimmed = trim($media);

        return $trimmed === '' ? null : $trimmed;
    }
}
