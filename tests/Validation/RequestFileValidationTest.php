<?php

declare(strict_types=1);

namespace Sahil\Tests\Validation;

use Sahil\Http\Request;
use Sahil\Validation\ValidationException;

/**
 * Request-level file validation: `Request::files()` feeds
 * `$request->validate()` through FormInput's upload merge.
 */
final class RequestFileValidationTest extends \PHPUnit\Framework\TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'req');
        file_put_contents($this->tmpFile, 'content');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    private function request(array $files = []): Request
    {
        return Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/upload', 'CONTENT_TYPE' => 'multipart/form-data'],
            form: ['title' => 'hello'],
            files: $files,
        );
    }

    public function test_request_exposes_uploaded_files(): void
    {
        $request = $this->request([
            'avatar' => ['tmp_name' => $this->tmpFile, 'name' => 'a.png', 'type' => 'image/png', 'error' => 0, 'size' => 7],
            'photos' => [
                'tmp_name' => [$this->tmpFile],
                'name' => ['p.jpg'], 'type' => ['image/jpeg'],
                'error' => [0], 'size' => [7],
            ],
        ]);

        self::assertInstanceOf(\Sahil\Http\UploadedFile::class, $request->file('avatar'));
        self::assertIsArray($request->files()['photos']);
        self::assertSame('a.png', $request->file('avatar')->clientName());
        self::assertNull($request->file('missing'));
    }

    public function test_request_validate_sees_file_fields(): void
    {
        $request = $this->request([
            'doc' => ['tmp_name' => $this->tmpFile, 'name' => 'd.txt', 'type' => 'text/plain', 'error' => 0, 'size' => 7],
        ]);

        $data = $request->validate([
            'title' => 'required|string',
            'doc' => 'required|file|mimetypes:text/plain',
        ]);

        self::assertSame('hello', $data['title']);
        self::assertInstanceOf(\Sahil\Http\UploadedFile::class, $data['doc']);
    }

    public function test_request_validate_rejects_a_failed_upload(): void
    {
        $request = $this->request([
            'doc' => ['tmp_name' => '', 'name' => 'd.txt', 'type' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ]);

        $this->expectException(ValidationException::class);
        $request->validate(['doc' => 'required|file']);
    }
}
