<?php

declare(strict_types=1);

namespace Sahil\Tests\Identification;

use PHPUnit\Framework\TestCase;
use Sahil\Http\Response;
use Sahil\Identification\FrameworkIdentity;
use Sahil\Identification\Identification;
use Sahil\Identification\InvalidIdentificationConfigurationException;

final class IdentificationTest extends TestCase
{
    private const DOCUMENT = '<!doctype html><html lang="en"><head>'
        .'<meta charset="utf-8"><title>Page</title></head><body><p>Hi</p></body></html>';

    public function test_defaults_enable_every_signal(): void
    {
        $identification = Identification::fromArray([]);

        self::assertTrue($identification->enabled());
        self::assertTrue($identification->poweredBy());
    }

    public function test_configuration_keys_are_validated(): void
    {
        $this->expectException(InvalidIdentificationConfigurationException::class);
        $this->expectExceptionMessage('identification.enabled must be a boolean');

        Identification::fromArray(['enabled' => 'yes']);
    }

    public function test_version_must_be_a_string_or_null(): void
    {
        $this->expectException(InvalidIdentificationConfigurationException::class);
        $this->expectExceptionMessage('identification.version must be a string or null');

        Identification::fromArray(['version' => 42]);
    }

    public function test_powered_by_header_is_added_to_every_response(): void
    {
        $response = Identification::fromArray([])->apply(Response::json(['ok' => true]));

        self::assertSame('SahilPHP', $response->header('X-Powered-By'));
        self::assertSame('{"ok":true}', $response->body(), 'non-document bodies are never modified');
    }

    public function test_powered_by_never_overwrites_an_application_header(): void
    {
        $response = Identification::fromArray([])
            ->apply(Response::text('x')->withHeader('X-Powered-By', 'Coffee'));

        self::assertSame('Coffee', $response->header('X-Powered-By'));
    }

    public function test_generator_meta_is_injected_inside_head(): void
    {
        $response = Identification::fromArray(['version' => '1.3.0'])
            ->apply(Response::html(self::DOCUMENT));

        self::assertStringContainsString(
            '<meta name="generator" content="SahilPHP 1.3.0">',
            $response->body(),
        );
        self::assertStringContainsString('</head>', $response->body());
        self::assertSame(1, substr_count($response->body(), 'name="generator"'));
    }

    public function test_version_comes_from_the_installed_package_when_unpinned(): void
    {
        $response = Identification::fromArray([])->apply(Response::html(self::DOCUMENT));

        $version = FrameworkIdentity::version();

        self::assertStringContainsString(
            sprintf('<meta name="generator" content="SahilPHP%s">', $version !== null ? ' '.$version : ''),
            $response->body(),
        );
    }

    public function test_expose_version_false_omits_the_version(): void
    {
        $response = Identification::fromArray(['expose_version' => false, 'version' => '1.3.0'])
            ->apply(Response::html(self::DOCUMENT));

        self::assertStringContainsString('<meta name="generator" content="SahilPHP">', $response->body());
    }

    public function test_bare_sahilphp_signature_is_completed_with_the_version(): void
    {
        $document = str_replace(
            '<meta charset="utf-8">',
            '<meta charset="utf-8"><meta name="generator" content="SahilPHP">',
            self::DOCUMENT,
        );

        $response = Identification::fromArray(['version' => '2.1.0'])
            ->apply(Response::html($document));

        self::assertSame(1, substr_count($response->body(), 'name="generator"'), 'no duplicate tag');
        self::assertStringContainsString('content="SahilPHP 2.1.0"', $response->body());
    }

    public function test_custom_generator_metadata_wins(): void
    {
        $document = str_replace(
            '<meta charset="utf-8">',
            '<meta charset="utf-8"><meta name="generator" content="My CMS">',
            self::DOCUMENT,
        );

        $response = Identification::fromArray(['version' => '1.3.0'])
            ->apply(Response::html($document));

        self::assertStringContainsString('content="My CMS"', $response->body());
        self::assertSame(1, substr_count($response->body(), 'name="generator"'));
    }

    public function test_an_already_versioned_signature_is_left_alone(): void
    {
        $document = str_replace(
            '<meta charset="utf-8">',
            '<meta charset="utf-8"><meta name="generator" content="SahilPHP 9.9.9">',
            self::DOCUMENT,
        );

        $response = Identification::fromArray(['version' => '1.3.0'])
            ->apply(Response::html($document));

        self::assertStringContainsString('content="SahilPHP 9.9.9"', $response->body());
    }

    public function test_markup_fragments_without_a_head_are_untouched(): void
    {
        $response = Identification::fromArray([])
            ->apply(Response::html('<p>fragment</p>'));

        self::assertSame('<p>fragment</p>', $response->body());
    }

    public function test_arbitrary_html_responses_are_not_modified(): void
    {
        // Only documents produced through Response::html() are fingerprinted;
        // a raw text/html payload (e.g. proxied third-party markup) passes
        // through byte-identical apart from the response headers.
        $response = Identification::fromArray([])
            ->apply(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], self::DOCUMENT));

        self::assertSame(self::DOCUMENT, $response->body());
        self::assertSame('SahilPHP', $response->header('X-Powered-By'));
    }

    public function test_disabled_identification_changes_nothing(): void
    {
        $response = Identification::fromArray(['enabled' => false])
            ->apply(Response::html(self::DOCUMENT));

        self::assertSame(self::DOCUMENT, $response->body());
        self::assertNull($response->header('X-Powered-By'));
    }

    public function test_powered_by_can_be_disabled_independently(): void
    {
        $response = Identification::fromArray(['powered_by' => false])
            ->apply(Response::html(self::DOCUMENT));

        self::assertNull($response->header('X-Powered-By'));
        self::assertStringContainsString('name="generator"', $response->body());
    }

    public function test_injection_recomputes_content_length(): void
    {
        $response = Identification::fromArray([])
            ->apply(Response::html(self::DOCUMENT));

        self::assertSame((string) strlen($response->body()), $response->header('Content-Length'));
    }

    public function test_normalize_version_accepts_release_versions_only(): void
    {
        self::assertSame('1.3.0', FrameworkIdentity::normalizeVersion('v1.3.0'));
        self::assertSame('1.3.0', FrameworkIdentity::normalizeVersion('1.3.0'));
        self::assertSame('2.0.0-beta.1', FrameworkIdentity::normalizeVersion('2.0.0-beta.1'));
        self::assertSame('1.4', FrameworkIdentity::normalizeVersion('1.4'));
        self::assertNull(FrameworkIdentity::normalizeVersion('dev-main'));
        self::assertNull(FrameworkIdentity::normalizeVersion('1.x-dev'));
        self::assertNull(FrameworkIdentity::normalizeVersion(''));
        self::assertNull(FrameworkIdentity::normalizeVersion(null));
    }
}
