<?php

declare(strict_types=1);

namespace Sahil\Http\Client;

use Sahil\Contracts\Http\Client\HttpTransportContract;

/**
 * cURL transport — TLS verification always on, manual redirect handling
 * (credentials never forwarded cross-origin), optional streaming into a
 * caller-owned sink.
 */
final class CurlTransport implements HttpTransportContract
{
    public function __construct()
    {
        if (!extension_loaded('curl')) {
            throw new HttpClientException('The curl PHP extension is required for outbound HTTP requests.');
        }
    }

    public function send(OutgoingRequest $request): ClientResponse
    {
        $current = $request;
        $response = $this->attempt($current);

        for ($i = 0; $i < $request->maxRedirects && $response->redirect(); $i++) {
            $location = $response->header('Location');

            if ($location === null) {
                break;
            }

            $next = $this->redirectTarget($current, $location);

            if (!self::sameOrigin($current->url, $next)) {
                $current = $current->withoutSensitiveHeaders();
            }

            // 301/302 turn POST/PUT/PATCH into GET; 303 always does;
            // 307/308 replay the request unchanged.
            $status = $response->status();

            if ($status === 303 || (($status === 301 || $status === 302) && !in_array($current->method, ['GET', 'HEAD'], true))) {
                $current = $current->rewritten('GET', $next);
            } else {
                $current = $current->withUrl($next);
            }

            $response = $this->attempt($current);
        }

        return $response;
    }

    private function attempt(OutgoingRequest $request): ClientResponse
    {
        $handle = curl_init($request->url);

        if ($handle === false) {
            throw new HttpClientException('Failed to initialize a curl request.');
        }

        $body = '';
        $headerLines = [];

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,   // redirects handled explicitly
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($request->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($request->timeout * 1000),
            CURLOPT_SSL_VERIFYPEER => true,    // TLS verification — never disabled
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $request): int {
                if ($request->sink !== null) {
                    $written = fwrite($request->sink, $chunk);

                    return $written === false ? 0 : $written;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headerLines): int {
                $len = strlen($line);
                $line = trim($line);

                if ($line !== '') {
                    $headerLines[] = $line;
                }

                return $len;
            },
            CURLOPT_NOBODY => $request->method === 'HEAD',
        ]);

        if ($request->resolve !== null) {
            $parts = parse_url($request->url);
            $pinHost = is_array($parts) && isset($parts['host']) ? $parts['host'] : '';
            $pinPort = isset($parts['port'])
                ? (int) $parts['port']
                : (($parts['scheme'] ?? 'https') === 'http' ? 80 : 443);
            $entries = [];

            foreach ($request->resolve as $ip) {
                $entries[] = sprintf('%s:%d:%s', $pinHost, $pinPort, $ip);
            }

            // The validated address IS the connection — no second DNS
            // lookup, no env-proxy detour. Host/SNI/cert verification
            // still use the original hostname (RESOLVE maps, not rewrites).
            curl_setopt($handle, CURLOPT_RESOLVE, $entries);
            curl_setopt($handle, CURLOPT_NOPROXY, '*');
        }

        if ($request->body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }

        $headers = [];

        foreach ($request->headers as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name.': '.$value;
            }
        }

        if ($headers !== []) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        }

        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        curl_close($handle);

        if ($ok === false || $errno !== 0) {
            throw new HttpClientException(sprintf(
                'HTTP %s to %s failed%s: %s',
                $request->method,
                $request->url,
                $errno === CURLE_OPERATION_TIMEDOUT ? ' (timeout)' : '',
                $error !== '' ? $error : 'transport error '.$errno,
            ));
        }

        return new ClientResponse($status, self::parseHeaders($headerLines), $body, $effectiveUrl);
    }

    /** @param list<string> $lines @return array<string, list<string>> */
    private static function parseHeaders(array $lines): array
    {
        $headers = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                $headers = []; // drop interim blocks (e.g. 100 Continue)

                continue;
            }

            if (($pos = strpos($line, ':')) !== false) {
                $headers[trim(substr($line, 0, $pos))][] = trim(substr($line, $pos + 1));
            }
        }

        return $headers;
    }

    private function redirectTarget(OutgoingRequest $request, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $base = parse_url($request->url);

        if ($base === false || !isset($base['host'])) {
            throw new HttpClientException(sprintf('Cannot resolve relative redirect [%s].', $location));
        }

        $origin = ($base['scheme'] ?? 'https').'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $base['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.$dir.$location;
    }

    private static function sameOrigin(string $a, string $b): bool
    {
        $pa = parse_url($a);
        $pb = parse_url($b);

        return $pa !== false && $pb !== false
            && ($pa['scheme'] ?? '') === ($pb['scheme'] ?? '')
            && ($pa['host'] ?? '') === ($pb['host'] ?? '')
            && ($pa['port'] ?? null) === ($pb['port'] ?? null);
    }
}
