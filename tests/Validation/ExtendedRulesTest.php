<?php

declare(strict_types=1);

namespace Sahil\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Sahil\Http\UploadedFile;
use Sahil\Validation\Validator;

/**
 * Rules added by the native-validation milestone: presence modifiers,
 * conditional required, formats, sizes, date variants, and file rules.
 */
final class ExtendedRulesTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'val');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    /* ---------- presence ---------- */

    public function test_present_requires_the_key_but_allows_null(): void
    {
        $v = new Validator(['x' => 'present']);
        self::assertTrue($v->validate(['x' => null])->passes());
        self::assertTrue($v->validate(['x' => ''])->passes());
        self::assertTrue($v->validate(['x' => 0])->passes());
        self::assertTrue($v->validate([])->fails());
    }

    public function test_filled_rejects_empty_values_only_when_present(): void
    {
        $v = new Validator(['x' => 'filled']);
        self::assertTrue($v->validate([])->passes());          // absent → optional
        self::assertTrue($v->validate(['x' => 'a'])->passes());
        self::assertTrue($v->validate(['x' => null])->fails());
        self::assertTrue($v->validate(['x' => ''])->fails());
        self::assertTrue($v->validate(['x' => []])->fails());
        // zero and false are real values, not "empty"
        self::assertTrue($v->validate(['x' => 0])->passes());
        self::assertTrue($v->validate(['x' => false])->passes());
    }

    public function test_missing_prohibits_the_key(): void
    {
        $v = new Validator(['x' => 'missing']);
        self::assertTrue($v->validate([])->passes());
        self::assertTrue($v->validate(['x' => null])->fails());
        self::assertTrue($v->validate(['x' => 'anything'])->fails());
    }

    /* ---------- conditional required ---------- */

    public function test_required_if(): void
    {
        $v = new Validator(['reason' => 'required_if:status,rejected|string']);
        self::assertTrue($v->validate(['status' => 'rejected'])->fails());
        self::assertTrue($v->validate(['status' => 'approved'])->passes());
        self::assertTrue($v->validate(['status' => 'rejected', 'reason' => 'x'])->passes());
    }

    public function test_required_unless(): void
    {
        $v = new Validator(['reason' => 'required_unless:status,approved|string']);
        self::assertTrue($v->validate(['status' => 'approved'])->passes());
        self::assertTrue($v->validate(['status' => 'rejected'])->fails());
    }

    public function test_required_with_and_without(): void
    {
        $v = new Validator(['b' => 'required_with:a|string']);
        self::assertTrue($v->validate(['a' => '1'])->fails());
        self::assertTrue($v->validate([])->passes());
        self::assertTrue($v->validate(['a' => ''])->passes()); // empty ≠ present

        $w = new Validator(['b' => 'required_without:a|string']);
        self::assertTrue($w->validate([])->fails());
        self::assertTrue($w->validate(['a' => 'x'])->passes());
        self::assertTrue($w->validate(['a' => 'x', 'b' => 'y'])->passes());
    }

    /* ---------- formats ---------- */

    public function test_ip_uuid_json(): void
    {
        $v = new Validator([
            'ip' => 'ip',
            'uuid' => 'uuid',
            'doc' => 'json',
        ]);
        self::assertTrue($v->validate([
            'ip' => '10.0.0.1', 'uuid' => '123e4567-e89b-12d3-a456-426614174000', 'doc' => '{"a":1}',
        ])->passes());
        self::assertTrue($v->validate(['ip' => 'nope', 'uuid' => 'x', 'doc' => '{bad'])->fails());
    }

    public function test_alpha_family(): void
    {
        $v = new Validator([
            'a' => 'alpha', 'an' => 'alpha_num', 'ad' => 'alpha_dash',
        ]);
        self::assertTrue($v->validate(['a' => 'abc', 'an' => 'abc123', 'ad' => 'a-b_c'])->passes());
        self::assertTrue($v->validate(['a' => 'ab1', 'an' => 'a-b', 'ad' => 'a b'])->fails());
        // unicode letters count
        self::assertTrue($v->validate(['a' => 'mwalimu', 'an' => 'x2', 'ad' => 'm-1_2'])->passes());
    }

    /* ---------- size / digits ---------- */

    public function test_size_digits_digits_between(): void
    {
        $v = new Validator([
            'code' => 'size:3',
            'pin' => 'digits:4',
            'otp' => 'digits_between:4,6',
        ]);
        self::assertTrue($v->validate(['code' => 'abc', 'pin' => '1234', 'otp' => '12345'])->passes());
        self::assertTrue($v->validate(['code' => 3, 'pin' => 1234, 'otp' => '12345'])->passes());
        self::assertTrue($v->validate(['code' => 'abcd', 'pin' => '123', 'otp' => '1234567'])->fails());
        self::assertTrue($v->validate(['code' => 'abc', 'pin' => '12a4', 'otp' => '12345'])->fails());
    }

    /* ---------- dates ---------- */

    public function test_date_format_and_or_equal_bounds(): void
    {
        $v = new Validator([
            'd' => 'date_format:Y-m-d',
            'start' => 'before_or_equal:2025-06-30',
            'end' => 'after_or_equal:2025-01-01',
        ]);
        self::assertTrue($v->validate(['d' => '2025-03-15', 'start' => '2025-06-30', 'end' => '2025-01-01'])->passes());
        self::assertTrue($v->validate(['d' => '2025-13-40', 'start' => '2025-07-01', 'end' => '2024-12-31'])->fails());
        // trailing garbage fails strict format
        self::assertTrue($v->validate(['d' => '2025-03-15x', 'start' => '2025-01-01', 'end' => '2025-01-01'])->fails());
    }

    /* ---------- files ---------- */

    private function upload(string $contents, string $name, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        file_put_contents($this->tmpFile, $contents);

        return UploadedFile::fromFilesEntry([
            'tmp_name' => $this->tmpFile,
            'name' => $name,
            'type' => 'application/octet-stream', // client claim — ignored
            'error' => $error,
            'size' => strlen($contents),
        ]);
    }

    public function test_file_rule_accepts_valid_uploads(): void
    {
        $v = new Validator(['doc' => 'file']);
        self::assertTrue($v->validate(['doc' => $this->upload('data', 'd.txt')])->passes());
        self::assertTrue($v->validate(['doc' => 'not-a-file'])->fails());
        self::assertTrue($v->validate(['doc' => $this->upload('x', 'd.txt', UPLOAD_ERR_PARTIAL)])->fails());
    }

    public function test_image_rule_sniffs_content(): void
    {
        // 1×1 transparent PNG
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $v = new Validator(['avatar' => 'image']);
        self::assertTrue($v->validate(['avatar' => $this->upload($png, 'a.png')])->passes());
        // text renamed to .png is NOT an image
        self::assertTrue($v->validate(['avatar' => $this->upload('not an image', 'evil.png')])->fails());
    }

    public function test_mimes_proves_extension_and_content(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $v = new Validator(['f' => 'mimes:png,jpg']);
        self::assertTrue($v->validate(['f' => $this->upload($png, 'a.png')])->passes());
        // right extension, wrong content → fail (executable renamed .png)
        self::assertTrue($v->validate(['f' => $this->upload('#!/bin/sh', 'a.png')])->fails());
        // wrong extension → fail
        self::assertTrue($v->validate(['f' => $this->upload($png, 'a.gif')])->fails());
    }

    public function test_mimes_rejects_unknown_extensions_even_with_valid_content(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $v = new Validator(['f' => 'mimes:png,xyzabc']);
        // Valid PNG content under an UNKNOWN extension must still fail —
        // the extension cannot be mapped to a MIME family to verify.
        self::assertTrue($v->validate(['f' => $this->upload($png, 'a.xyzabc')])->fails());
        // Same content under a recognized extension passes.
        self::assertTrue($v->validate(['f' => $this->upload($png, 'a.png')])->passes());
    }

    public function test_extensions_rule_still_checks_the_filename_only(): void
    {
        $v = new Validator(['f' => 'extensions:xyzabc']);
        // `extensions` is the filename rule — content is irrelevant to it.
        self::assertTrue($v->validate(['f' => $this->upload('anything', 'a.xyzabc')])->passes());
        self::assertTrue($v->validate(['f' => $this->upload('anything', 'a.png')])->fails());
    }

    public function test_mimetypes_and_extensions(): void
    {
        $v = new Validator([
            'doc' => 'mimetypes:text/plain',
            'f' => 'extensions:txt,csv',
        ]);
        self::assertTrue($v->validate([
            'doc' => $this->upload('plain text', 'd.txt'),
            'f' => $this->upload('a,b', 'd.csv'),
        ])->passes());
        self::assertTrue($v->validate([
            'doc' => $this->upload("\x89PNG", 'd.txt'),
            'f' => $this->upload('x', 'd.exe'),
        ])->fails());
    }

    public function test_file_size_bounds_use_bytes(): void
    {
        $v = new Validator(['f' => 'file|min:4|max:8']);
        self::assertTrue($v->validate(['f' => $this->upload('12345', 'x.bin')])->passes());
        self::assertTrue($v->validate(['f' => $this->upload(str_repeat('x', 100), 'x.bin')])->fails());
    }

    /* ---------- rule-string parsing ---------- */

    public function test_escaped_pipe_keeps_regex_one_rule(): void
    {
        $v = new Validator(['code' => 'required|string|regex:/^(a\\|b)$/']);
        // \| inside the descriptor stays ONE regex rule: /^(a|b)$/ unescaped.
        self::assertTrue($v->validate(['code' => 'a'])->passes());
        self::assertTrue($v->validate(['code' => 'b'])->passes());
        self::assertTrue($v->validate(['code' => 'axb'])->fails());
    }

    /* ---------- facade-style entry ---------- */

    public function test_make_validates_immediately_and_exposes_accessors(): void
    {
        $result = Validator::make(
            ['email' => 'not-an-email', 'age' => '30', 'extra' => 'dropped'],
            ['email' => 'required|email', 'age' => 'integer|min:18'],
        );

        self::assertTrue($result->fails());
        self::assertSame('This field must be a valid email address.', $result->first());
        self::assertSame(['This field must be a valid email address.'], $result->errorsFor('email'));
        self::assertSame(['age' => 30], $result->validated()); // unruled input excluded
    }
}
