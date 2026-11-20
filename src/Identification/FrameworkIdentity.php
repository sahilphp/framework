<?php

declare(strict_types=1);

namespace Sahil\Identification;

use Composer\InstalledVersions;
use Throwable;

/**
 * The framework's public identity: the `SahilPHP` name and the installed
 * package version.
 *
 * The version is resolved from Composer's runtime metadata
 * (`sahilphp/framework` in the application's lock file) rather than a
 * hard-coded constant, so released installations report the exact version
 * they run while development checkouts (`dev-*`, `*.x-dev`) report no
 * version at all — a fingerprint must never claim a version that was not
 * released.
 */
final class FrameworkIdentity
{
    /** Public framework name used in generator metadata and HTTP identification. */
    public const NAME = 'SahilPHP';

    /** Composer package whose installed version is reported. */
    public const PACKAGE = 'sahilphp/framework';

    private function __construct()
    {
    }

    /**
     * The installed framework version (`1.3.0`), or null when the version
     * cannot be determined or the installation is a development checkout.
     */
    public static function version(): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            if (!InstalledVersions::isInstalled(self::PACKAGE)) {
                return null;
            }

            $pretty = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (Throwable) {
            return null;
        }

        return self::normalizeVersion(is_string($pretty) ? $pretty : null);
    }

    /**
     * Normalize a Composer "pretty" version to a release number the public
     * fingerprint may carry: `v1.3.0` → `1.3.0`, development versions → null.
     */
    public static function normalizeVersion(?string $pretty): ?string
    {
        if ($pretty === null) {
            return null;
        }

        $version = preg_replace('/^v(?=\d)/i', '', trim($pretty));

        if (!is_string($version)
            || preg_match('/^\d+\.\d+(\.\d+)?([.\-][0-9A-Za-z.]+)?$/', $version) !== 1) {
            return null;
        }

        return $version;
    }

    /**
     * `SahilPHP`, or `SahilPHP 1.3.0` when version disclosure is requested
     * and a released version is installed.
     */
    public static function signature(bool $withVersion = true, ?string $version = null): string
    {
        $resolved = $version ?? self::version();

        return $withVersion && $resolved !== null
            ? self::NAME.' '.$resolved
            : self::NAME;
    }

    /**
     * The `<meta name="generator">` tag for generated HTML documents.
     */
    public static function generatorMetaTag(bool $withVersion = true, ?string $version = null): string
    {
        return sprintf(
            '<meta name="generator" content="%s">',
            self::signature($withVersion, $version),
        );
    }

    /**
     * The `X-Powered-By` header value. The name alone is disclosed — the
     * header never carries version information.
     */
    public static function poweredBy(): string
    {
        return self::NAME;
    }
}
