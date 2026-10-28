<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Sahil\Mail\Html;

final class HtmlTest extends TestCase
{
    public function test_escape_neutralises_tags_quotes_and_ampersands(): void
    {
        self::assertSame(
            '&lt;script&gt;&quot;x&quot; &amp; &apos;y&apos;&lt;/script&gt;',
            Html::escape('<script>"x" & \'y\'</script>'),
        );
    }

    public function test_escape_keeps_valid_utf8(): void
    {
        self::assertSame('Grüße — 東京', Html::escape('Grüße — 東京'));
    }

    public function test_text_to_html_escapes_user_content_and_separates_paragraphs(): void
    {
        $html = Html::fromText("Hello <b>Ada</b>\nline two\n\nSecond & last");

        self::assertStringContainsString('<p>Hello &lt;b&gt;Ada&lt;/b&gt;<br>', $html);
        self::assertStringContainsString('<p>Second &amp; last</p>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('<!doctype html>', $html);
        self::assertStringContainsString('<meta charset="utf-8">', $html);
    }

    public function test_text_to_html_never_emits_script_markup_from_text(): void
    {
        $html = Html::fromText('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_html_to_text_keeps_readable_content_and_link_targets(): void
    {
        $text = Html::toText(
            '<p>Hello <strong>Ada</strong></p>'
            .'<p><a href="https://example.com/docs">Docs</a></p>'
            .'<script>alert(1)</script>'
            .'<p>A &amp; B</p>',
        );

        self::assertSame("Hello Ada\n\nDocs (https://example.com/docs)\n\nA & B", $text);
        self::assertStringNotContainsString('alert', $text);
    }

    public function test_html_to_text_turns_lists_into_dashes(): void
    {
        self::assertSame("- one\n- two", Html::toText('<ul><li>one</li><li>two</li></ul>'));
    }

    public function test_html_to_text_decodes_entities_and_non_breaking_spaces(): void
    {
        self::assertSame('5 < 6 & 7 > 2', Html::toText('<p>5 &lt; 6 &amp; 7 &gt;&nbsp;2</p>'));
    }

    public function test_round_trip_from_text_preserves_the_words(): void
    {
        $text = "First line\nsecond line\n\nAnother paragraph";

        self::assertSame($text, Html::toText(Html::fromText($text)));
    }
}
