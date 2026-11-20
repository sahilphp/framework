<?php

declare(strict_types=1);

namespace Sahil\Identification;

use Sahil\Http\Response;

/**
 * Framework technology identification: applies the public `SahilPHP`
 * fingerprint to outgoing responses.
 *
 * Two signals, controlled independently:
 *
 *  - `X-Powered-By: SahilPHP` — a response header applied to every response
 *    (never to an application-set value, and never carrying a version);
 *  - `<meta name="generator" content="SahilPHP[ x.y.z]">` — injected into
 *    HTML documents produced by the framework's own document pipeline
 *    (responses marked by {@see Response::html()}, which includes rendered
 *    views, frontend documents and error pages).
 *
 * HTML documents are fingerprinted conservatively:
 *  - only documents built through {@see Response::html()} are touched, so
 *    arbitrary `text/html` payloads (proxied or third-party markup) pass
 *    through byte-identical;
 *  - a document that already declares `name="generator"` keeps its value —
 *    application control over document metadata wins, with one exception:
 *    a bare `content="SahilPHP"` signature (the standard unversioned tag)
 *    is completed with the installed version when version disclosure is on;
 *  - markup fragments without a `<head>` are left alone.
 */
final class Identification
{
    public function __construct(
        private readonly bool $enabled = true,
        private readonly bool $exposeVersion = true,
        private readonly bool $poweredBy = true,
        private readonly ?string $version = null,
    ) {
    }

    /**
     * Build from the `identification` configuration namespace.
     *
     * Supported keys (all optional):
     *  - `enabled` (bool, default true) — master switch;
     *  - `expose_version` (bool, default true) — include the installed
     *    framework version in generator metadata;
     *  - `powered_by` (bool, default true) — send `X-Powered-By: SahilPHP`;
     *  - `version` (string|null, default null) — pin the reported version;
     *    null resolves the installed `sahilphp/framework` version.
     *
     * @param array<string, mixed> $configuration
     */
    public static function fromArray(array $configuration): self
    {
        $boolean = static function (string $key, bool $default) use ($configuration): bool {
            $value = $configuration[$key] ?? $default;

            if (!is_bool($value)) {
                throw new InvalidIdentificationConfigurationException(sprintf(
                    'identification.%s must be a boolean, got %s.',
                    $key,
                    get_debug_type($value),
                ));
            }

            return $value;
        };

        $version = $configuration['version'] ?? null;

        if ($version !== null && !is_string($version)) {
            throw new InvalidIdentificationConfigurationException(sprintf(
                'identification.version must be a string or null, got %s.',
                get_debug_type($version),
            ));
        }

        return new self(
            $boolean('enabled', true),
            $boolean('expose_version', true),
            $boolean('powered_by', true),
            FrameworkIdentity::normalizeVersion($version),
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function poweredBy(): bool
    {
        return $this->poweredBy;
    }

    /**
     * The generator signature for this configuration, e.g. `SahilPHP` or
     * `SahilPHP 1.3.0`.
     */
    public function signature(): string
    {
        return FrameworkIdentity::signature($this->exposeVersion, $this->version);
    }

    public function generatorMetaTag(): string
    {
        return FrameworkIdentity::generatorMetaTag($this->exposeVersion, $this->version);
    }

    /**
     * Apply the configured fingerprint to a response.
     */
    public function apply(Response $response): Response
    {
        if (!$this->enabled) {
            return $response;
        }

        if ($this->poweredBy && $response->header('X-Powered-By') === null) {
            $response = $response->withHeader('X-Powered-By', FrameworkIdentity::poweredBy());
        }

        if ($response->isHtmlDocument()) {
            $body = $this->identifyDocument($response->body());

            if ($body !== null) {
                $response = $response->withBody($body);
            }
        }

        return $response;
    }

    /**
     * Return the document with generator metadata applied, or null when no
     * change is required (no `<head>`, or a generator declaration the
     * application owns is already present).
     */
    private function identifyDocument(string $html): ?string
    {
        if (preg_match(
            '/<meta\b[^>]*\bname\s*=\s*(["\']?)generator\1[^>]*>/i',
            $html,
            $found,
            PREG_OFFSET_CAPTURE,
        ) === 1) {
            return $this->completeBareSignature($html, $found[0][0], $found[0][1]);
        }

        if (preg_match('/<head\b[^>]*>/i', $html, $head, PREG_OFFSET_CAPTURE) === 1) {
            $position = $head[0][1] + strlen($head[0][0]);

            return substr($html, 0, $position)."\n".$this->generatorMetaTag().substr($html, $position);
        }

        if (preg_match('/<\/head\s*>/i', $html, $close, PREG_OFFSET_CAPTURE) === 1) {
            $position = $close[0][1];

            return substr($html, 0, $position).$this->generatorMetaTag()."\n".substr($html, $position);
        }

        return null;
    }

    /**
     * A document that already declares `generator` keeps its metadata —
     * except a bare `content="SahilPHP"` signature, which is completed with
     * the installed version so version detection works for applications
     * carrying the standard unversioned tag.
     */
    private function completeBareSignature(string $html, string $tag, int $offset): ?string
    {
        if (!$this->exposeVersion || $this->version() === null) {
            return null;
        }

        if (preg_match(
            '/content\s*=\s*(?:"(?<dq>[^"]*)"|\'(?<sq>[^\']*)\'|(?<uq>[^\s>]+))/i',
            $tag,
            $content,
        ) !== 1) {
            return null;
        }

        $current = $content['dq'] !== '' ? $content['dq'] : ($content['sq'] !== '' ? $content['sq'] : ($content['uq'] ?? ''));

        if (strcasecmp(trim($current), FrameworkIdentity::NAME) !== 0) {
            return null;
        }

        $upgraded = str_replace(
            $content[0],
            'content="'.$this->signature().'"',
            $tag,
        );

        return substr_replace($html, $upgraded, $offset, strlen($tag));
    }

    private function version(): ?string
    {
        return $this->version ?? FrameworkIdentity::version();
    }
}
