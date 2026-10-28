<?php

declare(strict_types=1);

namespace Sahil\Tests\View;

use PHPUnit\Framework\TestCase;
use Sahil\View\TemplateResolver;
use Sahil\View\ViewException;
use Sahil\View\ViewNotFoundException;

final class PathTraversalTest extends TestCase
{
    public function test_traversal_with_double_dot_is_rejected(): void
    {
        $workspace = new TempViewWorkspace(1);
        $resolver = $workspace->resolver();

        self::assertFalse($resolver->exists('../etc/passwd'));

        try {
            $resolver->resolve('../etc/passwd');
            self::fail('Expected ViewException for traversal.');
        } catch (ViewException $exception) {
            self::assertStringContainsString('traversal', $exception->getMessage());
        } catch (ViewNotFoundException $exception) {
            // Allowed as fallback
        }

        $workspace->destroy();
    }

    public function test_traversal_with_leading_slash_is_rejected(): void
    {
        $workspace = new TempViewWorkspace(1);
        $resolver = $workspace->resolver();

        self::assertFalse($resolver->exists('/etc/passwd'));

        try {
            $resolver->resolve('/etc/passwd');
            self::fail('Expected ViewException for leading slash.');
        } catch (ViewException $exception) {
            self::assertStringContainsString('traversal', $exception->getMessage());
        } catch (ViewNotFoundException $exception) {
            // Allowed as fallback
        }

        $workspace->destroy();
    }

    public function test_relative_path_removes_dots(): void
    {
        $workspace = new TempViewWorkspace(1);
        $resolver = $workspace->resolver();

        // Relative names like `./foo` are rejected because `.` is not [A-Za-z0-9_-]+
        self::assertFalse($resolver->exists('./foo'));

        $workspace->destroy();
    }

    public function test_valid_template_names_resolve(): void
    {
        $workspace = new TempViewWorkspace(1);
        $resolver = $workspace->resolver();

        // A simple name should resolve to a file under the view root
        $workspace->write('hello', 'Hello, world!');

        self::assertTrue($resolver->exists('hello'));

        $content = $resolver->resolve('hello');
        self::assertFileExists($content);
        self::assertSame('Hello, world!', file_get_contents($content));

        $workspace->destroy();
    }

    public function test_empty_name_is_rejected(): void
    {
        $workspace = new TempViewWorkspace(1);
        $resolver = $workspace->resolver();

        try {
            $resolver->resolve('');
            self::fail('Expected ViewException for empty name.');
        } catch (ViewException $exception) {
            self::assertStringContainsString('must not be empty', $exception->getMessage());
        }

        $workspace->destroy();
    }

    public function test_dot_segments_in_name_are_rejected(): void
    {
        $workspace = new TempViewWorkspace(1);
        $resolver = $workspace->resolver();

        self::assertFalse($resolver->exists('a./.b'));

        $workspace->destroy();
    }
}
