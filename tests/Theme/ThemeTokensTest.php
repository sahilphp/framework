<?php

declare(strict_types=1);

namespace Sahil\Tests\Theme;

use PHPUnit\Framework\TestCase;
use Sahil\Theme\ThemeException;
use Sahil\Theme\ThemeTokens;

final class ThemeTokensTest extends TestCase
{
    public function test_the_sample_round_trips_through_json(): void
    {
        $sample = ThemeTokens::sample();
        $decoded = json_decode($sample->toJson(), true, 512, JSON_THROW_ON_ERROR);

        $tokens = ThemeTokens::fromArray($decoded);

        self::assertSame('sahil-neutral', $tokens->name);
        self::assertSame('#2563eb', $tokens->colors['primary']['light']);
        self::assertSame('#60a5fa', $tokens->colors['primary']['dark']);
        self::assertSame('1.5', $tokens->typography['line-heights']['normal']);
        self::assertSame('1rem', $tokens->spacing['md']);
    }

    public function test_light_only_colors_default_to_the_same_dark_value(): void
    {
        $data = ThemeTokens::sample()->toArray();
        $data['colors']['brand'] = '#123456';

        $tokens = ThemeTokens::fromArray($data);

        self::assertSame(
            ['light' => '#123456', 'dark' => '#123456'],
            $tokens->colors['brand']
        );
    }

    public function test_theme_must_be_an_object(): void
    {
        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('must be a JSON object');

        ThemeTokens::fromArray(['not', 'an', 'object']);
    }

    public function test_colors_are_required(): void
    {
        $data = ThemeTokens::sample()->toArray();
        unset($data['colors']);

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('colors must be a non-empty object');

        ThemeTokens::fromArray($data);
    }

    public function test_css_injection_is_rejected(): void
    {
        $data = ThemeTokens::sample()->toArray();
        $data['colors']['primary'] = ['light' => '#fff; body { display: none }', 'dark' => '#fff'];

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('contains characters that are not allowed');

        ThemeTokens::fromArray($data);
    }

    public function test_invalid_color_syntax_is_rejected(): void
    {
        $data = ThemeTokens::sample()->toArray();
        $data['colors']['primary'] = ['light' => '1rem', 'dark' => '#fff'];

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('not a supported CSS color value');

        ThemeTokens::fromArray($data);
    }

    public function test_malformed_hex_colors_are_rejected(): void
    {
        $data = ThemeTokens::sample()->toArray();
        $data['colors']['primary'] = ['light' => '#12', 'dark' => '#60a5fa'];

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('not a supported CSS color value');

        ThemeTokens::fromArray($data);
    }

    public function test_spacing_must_be_a_css_length(): void
    {
        $data = ThemeTokens::sample()->toArray();
        $data['spacing']['md'] = 'wide';

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('must be a CSS length');

        ThemeTokens::fromArray($data);
    }

    public function test_unknown_typography_keys_are_rejected(): void
    {
        $data = ThemeTokens::sample()->toArray();
        $data['typography']['font-size'] = '1rem';

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('unknown key [font-size]');

        ThemeTokens::fromArray($data);
    }

    public function test_unsupported_versions_are_rejected(): void
    {
        $data = ThemeTokens::sample()->toArray();
        $data['version'] = 2;

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('unsupported version 2');

        ThemeTokens::fromArray($data);
    }

    public function test_breakpoints_require_names_and_lengths(): void
    {
        $data = ThemeTokens::sample()->toArray();
        $data['breakpoints']['Not Valid'] = '40rem';

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('lowercase letters, digits and hyphens');

        ThemeTokens::fromArray($data);
    }

    public function test_invalid_json_reports_the_source_file(): void
    {
        $path = sys_get_temp_dir().'/sahil-theme-invalid-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, '{"colors":');

        try {
            ThemeTokens::fromFile($path);
            self::fail('Expected invalid JSON to throw.');
        } catch (ThemeException $error) {
            self::assertStringContainsString($path, $error->getMessage());
            self::assertStringContainsString('not valid JSON', $error->getMessage());
        } finally {
            @unlink($path);
        }
    }
}
