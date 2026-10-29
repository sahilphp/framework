<?php

declare(strict_types=1);

namespace Sahil\Tests\View;

use PHPUnit\Framework\TestCase;
use Sahil\View\ExpressionCompiler;

final class ExpressionTest extends TestCase
{
    public function test_number_literal(): void
    {
        $php = (new ExpressionCompiler())->compile('42');
        self::assertSame('42', $php);
    }

    public function test_string_literal(): void
    {
        $php = (new ExpressionCompiler())->compile("'hello'");
        self::assertSame("'hello'", $php);
    }

    public function test_boolean_literal_true(): void
    {
        $php = (new ExpressionCompiler())->compile('true');
        self::assertSame('true', $php);
    }

    public function test_boolean_literal_false(): void
    {
        $php = (new ExpressionCompiler())->compile('false');
        self::assertSame('false', $php);
    }

    public function test_null_literal(): void
    {
        $php = (new ExpressionCompiler())->compile('null');
        self::assertSame('null', $php);
    }

    public function test_identifier_lookup(): void
    {
        $php = (new ExpressionCompiler())->compile('$item');
        self::assertSame('$__ctx->get(\'$item\')', $php);
    }

    public function test_dotted_path(): void
    {
        $php = (new ExpressionCompiler())->compile('user.name');
        self::assertSame('$__ctx->get(\'user.name\')', $php);
    }

    public function test_path_with_integer_key(): void
    {
        $php = (new ExpressionCompiler())->compile('items.0.name');
        self::assertSame('$__ctx->get(\'items.0.name\')', $php);
    }

    public function test_function_count(): void
    {
        $php = (new ExpressionCompiler())->compile('count(posts)');
        self::assertStringContainsString('ViewHelpers::count', $php);
        self::assertStringContainsString('$__ctx->get', $php);
    }

    public function test_function_empty(): void
    {
        $php = (new ExpressionCompiler())->compile('empty(x)');
        self::assertStringContainsString('isEmpty', $php);
    }

    public function test_function_upper(): void
    {
        $php = (new ExpressionCompiler())->compile('upper(title)');
        self::assertStringContainsString('ViewHelpers::upper', $php);
    }

    public function test_function_trim(): void
    {
        $php = (new ExpressionCompiler())->compile('trim(name)');
        self::assertStringContainsString('ViewHelpers::trim', $php);
    }

    public function test_function_first(): void
    {
        $php = (new ExpressionCompiler())->compile('first(items)');
        self::assertStringContainsString('ViewHelpers::first', $php);
    }

    public function test_function_last(): void
    {
        $php = (new ExpressionCompiler())->compile('last(items)');
        self::assertStringContainsString('ViewHelpers::last', $php);
    }

    public function test_function_join(): void
    {
        $php = (new ExpressionCompiler())->compile('join(glue, items)');
        self::assertStringContainsString('ViewHelpers::join', $php);
    }

    public function test_in_operator(): void
    {
        $php = (new ExpressionCompiler())->compile('1 in [1, 2]');
        self::assertStringContainsString('ViewHelpers::contains', $php);
    }

    public function test_not_in_operator(): void
    {
        $php = (new ExpressionCompiler())->compile('1 not in [1, 2]');
        self::assertStringContainsString('ViewHelpers::contains', $php);
    }

    public function test_ternary_expression(): void
    {
        $php = (new ExpressionCompiler())->compile('a > b ? a : b');
        self::assertStringContainsString('?', $php);
        self::assertStringContainsString(':', $php);
    }

    public function test_elvis_ternary(): void
    {
        $php = (new ExpressionCompiler())->compile('a ?: b');
        self::assertStringContainsString('?:', $php);
    }

    public function test_logical_and(): void
    {
        $php = (new ExpressionCompiler())->compile('a and b');
        self::assertStringContainsString('&&', $php);
    }

    public function test_logical_or(): void
    {
        $php = (new ExpressionCompiler())->compile('a or b');
        self::assertStringContainsString('||', $php);
    }

    public function test_negative_unary(): void
    {
        $php = (new ExpressionCompiler())->compile('-value');
        self::assertStringContainsString('-', $php);
    }

    public function test_parenthesised_expression(): void
    {
        $php = (new ExpressionCompiler())->compile('(a + b) * c');
        self::assertStringContainsString('(', $php);
        self::assertStringContainsString(')', $php);
    }

    public function test_unknown_function_throws(): void
    {
        $this->expectException(\Sahil\View\ViewSyntaxException::class);

        (new ExpressionCompiler())->compile('make_up_function()');
    }

    public function test_wrong_arity_throws(): void
    {
        $this->expectException(\Sahil\View\ViewSyntaxException::class);

        (new ExpressionCompiler())->compile('count()');
        (new ExpressionCompiler())->compile('count(a, b)');
    }

    public function test_empty_expression_throws(): void
    {
        $this->expectException(\Sahil\View\ViewSyntaxException::class);

        (new ExpressionCompiler())->compile('');
    }

    public function test_unterminated_string_throws(): void
    {
        $this->expectException(\Sahil\View\ViewSyntaxException::class);

        (new ExpressionCompiler())->compile("'unterminated");
    }

    public function test_unexpected_character_throws(): void
    {
        $this->expectException(\Sahil\View\ViewSyntaxException::class);

        (new ExpressionCompiler())->compile('$@invalid');
    }

    public function test_dollar_variable_rejected(): void
    {
        $this->expectException(\Sahil\View\ViewSyntaxException::class);

        (new ExpressionCompiler())->compile('$user.name');
    }
}
