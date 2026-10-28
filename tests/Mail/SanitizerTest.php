<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Sahil\Mail\Sanitizer;

final class SanitizerTest extends TestCase
{
    public function test_removes_auth_payloads(): void
    {
        $clean = Sanitizer::error('SMTP error after AUTH PLAIN AHVzZXIAcGFzc3dvcmQ= was rejected.');

        self::assertStringNotContainsString('AHVzZXIAcGFzc3dvcmQ=', $clean);
        self::assertStringContainsString('[redacted]', $clean);
    }

    public function test_removes_password_assignments(): void
    {
        $clean = Sanitizer::error('Login failed: password=hunter2 and token: abcdef');

        self::assertStringNotContainsString('hunter2', $clean);
        self::assertStringNotContainsString('abcdef', $clean);
        self::assertSame('Login failed: password=[redacted] and token: [redacted]', $clean);
    }

    public function test_removes_credentials_from_dsn_style_urls(): void
    {
        $clean = Sanitizer::error('Unable to connect to smtp://ada:s3cret@mail.example.com:587.');

        self::assertStringNotContainsString('s3cret', $clean);
        self::assertStringContainsString('smtp://[redacted]@mail.example.com:587', $clean);
    }

    public function test_collapses_line_breaks_and_truncates(): void
    {
        $clean = Sanitizer::error("line one\nline two\r\nline three");

        self::assertSame('line one line two line three', $clean);

        $long = Sanitizer::error(str_repeat('a', 900));

        self::assertSame(501, mb_strlen($long, 'UTF-8'));
        self::assertStringEndsWith('…', $long);
    }

    public function test_removes_explicitly_supplied_credentials(): void
    {
        $clean = Sanitizer::error(
            'Failed to authenticate on SMTP server with username "ada@mail.example.com" using PLAIN.',
            ['ada@mail.example.com', 's3cret-token'],
        );

        self::assertStringNotContainsString('ada@mail.example.com', $clean);
        self::assertStringNotContainsString('s3cret-token', $clean);
        self::assertStringContainsString('username "[redacted]"', $clean);
    }

    public function test_leaves_ordinary_messages_alone(): void
    {
        self::assertSame(
            'Expected response code "250" but got code "550", with message "5.1.1 No such user".',
            Sanitizer::error('Expected response code "250" but got code "550", with message "5.1.1 No such user".'),
        );
    }
}
