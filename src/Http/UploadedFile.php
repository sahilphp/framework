<?php

declare(strict_types=1);

namespace Sahil\Http;

/**
 * One uploaded file, proven by its own bytes — never by client metadata.
 *
 * Wraps a `$_FILES` entry (or an explicit path, for non-HTTP contexts like
 * queued jobs and tests). `mime()` sniffs the real content through finfo;
 * `size()` measures the file on disk; the client-supplied name and type
 * are advisory only and exposed as `clientName()`/`clientType()`.
 *
 * Validation rules (`file`, `image`, `mimes`, `mimetypes`, `extensions`,
 * and byte-size `min:`/`max:`/`size:`/`between:`) operate on this object.
 * Nothing is stored or moved as a side effect — persistence stays with
 * `StorageManager`.
 */
final class UploadedFile
{
    private function __construct(
        private readonly ?string $path,
        private readonly ?string $clientName,
        private readonly ?string $clientType,
        private readonly int $error,
    ) {
    }

    /**
     * Build from a raw `$_FILES` entry. A missing/failed upload yields an
     * object whose `isValid()` is false — validation rules reject it with
     * their own message instead of the constructor throwing.
     *
     * @param array<string, mixed>|null $entry
     */
    public static function fromFilesEntry(?array $entry): ?self
    {
        if ($entry === null) {
            return null;
        }

        $path = $entry['tmp_name'] ?? null;

        return new self(
            is_string($path) && $path !== '' ? $path : null,
            isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : null,
            isset($entry['type']) && is_string($entry['type']) ? $entry['type'] : null,
            (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
        );
    }

    /** Wrap an existing file path (jobs, tests, console commands). */
    public static function fromPath(string $path, ?string $clientName = null): self
    {
        return new self($path, $clientName ?? basename($path), null, UPLOAD_ERR_OK);
    }

    /** The upload completed and the file is actually readable. */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK
            && $this->path !== null
            && is_file($this->path)
            && is_readable($this->path);
    }

    /** Server-side size in bytes, or null when unreadable. */
    public function size(): ?int
    {
        if ($this->path === null || !is_file($this->path)) {
            return null;
        }

        $size = @filesize($this->path);

        return $size === false ? null : $size;
    }

    /**
     * MIME type sniffed from the file's contents via finfo — NOT the
     * client-supplied Content-Type. Null when the file cannot be read.
     */
    public function mime(): ?string
    {
        if (!$this->isValid()) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($this->path);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /** Lowercase filename extension from the client-supplied name. */
    public function extension(): ?string
    {
        if ($this->clientName === null || !str_contains($this->clientName, '.')) {
            return null;
        }

        return strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    public function clientName(): ?string
    {
        return $this->clientName;
    }

    public function clientType(): ?string
    {
        return $this->clientType;
    }

    /** The filesystem path the transport can stream from. */
    public function path(): ?string
    {
        return $this->path;
    }

    public function error(): int
    {
        return $this->error;
    }
}
