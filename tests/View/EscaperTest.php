<?php

declare(strict_types=1);

namespace Sahil\Tests\View;

use PHPUnit\Framework\TestCase;
use Sahil\View\Escaper;
use Sahil\View\ViewRenderException;

final class EscaperTest extends TestCase
{
    public function test_html_escapes_every_dangerous_character(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;a&#039;',
            Escaper::html('<script>alert("x")</script> & \'a\''),
        );
    }

    public function test_html_escapes_both_quote_styles(): void
    {
        $escaped = Escaper::html('"double" and \'single\'');

        self::assertStringNotContainsString('"', $escaped);
        self::assertStringNotContainsString("'", $escaped);
        self::assertSame('&quot;double&quot; and &#039;single&#039;', $escaped);
    }

    public function test_null_renders_as_nothing_and_scalars_render_verbatim(): void
    {
        self::assertSame('', Escaper::html(null));
        self::assertSame('7', Escaper::html(7));
        self::assertSame('1.5', Escaper::html(1.5));
        self::assertSame('1', Escaper::html(true));
        self::assertSame('', Escaper::html(false));
    }

    public function test_invalid_utf8_is_substituted_instead_of_truncating_the_page(): void
    {
        $result = Escaper::html("broken: \xC3\x28");

        self::assertStringContainsString('broken: ', $result);
        $this->assertStringContainsString("\xEF\xBF\xBD", substr($result, strlen('broken: ')), 'ENT_SUBSTITUTE keeps the document well-formed');
    }

    public function test_stringable_objects_are_escaped(): void
    {
        $value = new class () implements \Stringable {
            public function __toString(): string
            {
                return '<b>name</b>';
            }
        };

        self::assertSame('&lt;b&gt;name&lt;/b&gt;', Escaper::html($value));
    }

    public function test_structures_are_refused_rather_than_printed_as_array(): void
    {
        try {
            Escaper::html(['a' => 1]);
            $this->fail('Expected an array to be refused.');
        } catch (ViewRenderException $exception) {
            $this->assertStringContainsString('array', $exception->getMessage());
            $this->assertStringContainsString('@json', $exception->getMessage(), 'the message points at the escape hatch');
        }

        $this->expectException(ViewRenderException::class);

        Escaper::html(new \stdClass());
    }

    public function test_raw_is_unconditional_and_only_reachable_by_explicit_call(): void
    {
        self::assertSame('<b>x</b>', Escaper::raw('<b>x</b>'));
        self::assertSame('', Escaper::raw(null));
        self::assertSame('12', Escaper::raw(12));
    }

    public function test_raw_also_refuses_structures(): void
    {
        $this->expectException(ViewRenderException::class);

        Escaper::raw(['nope']);
    }

    public function test_json_escapes_script_breaking_characters(): void
    {
        $encoded = Escaper::json(['html' => '</script><img src=x onerror=alert(1)>', 'quote' => ' "\'']);

        $this->assertStringNotContainsString('<', $encoded);
        $this->assertStringNotContainsString('>', $encoded);
        $this->assertStringNotContainsString('\"', $encoded, 'quotes are hex-encoded, so the payload cannot break an attribute');
        $this->assertStringNotContainsString("'", $encoded);
        $this->assertStringContainsString('\u003C', $encoded);
    }

    public function test_json_round_trips_to_the_original_value(): void
    {
        $value = ['n' => 1, 'list' => [true, null, 'a<b'], 'unicode' => 'Sahil \u2713'];

        $decoded = json_decode(Escaper::json($value), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($value, $decoded);
    }

    public function test_json_refuses_unencodable_values(): void
    {
        $this->expectException(ViewRenderException::class);

        Escaper::json(["invalid \xC3\x28 utf8"]);
    }
}
