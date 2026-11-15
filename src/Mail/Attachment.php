<?php

declare(strict_types=1);

namespace Sahil\Mail;

use Throwable;

/**
 * One attachment on a {@see Message}: a file on disk, or in-memory bytes.
 *
 * Path attachments are kept as references, so queueing a message serializes
 * a descriptor — not the file's bytes — and large files are streamed to the
 * transport rather than held in memory. Existence and readability are only
 * asserted when the content is actually needed (send time), which also lets
 * a queued job fail with a descriptive error if the file vanished.
 *
 * Inline attachments carry a content-id so the HTML body can reference them
 * (`<img src="cid:...">`). {@see Message::embed()} assigns and returns a
 * stable id, so the markup can be composed before the message is sent.
 */
final class Attachment
{
    /**
     * @param string|null $path absolute or relative path to a file
     * @param string|null $body in-memory contents (mutually exclusive with $path)
     * @param string|null $name filename presented to the recipient
     * @param string|null $mime MIME type; guessed from the name when null
     * @param bool $inline whether the resource is embedded for the HTML body
     * @param string|null $contentId explicit content-id for inline resources
     */
    private function __construct(
        public readonly ?string $path,
        public readonly ?string $body,
        public readonly ?string $name,
        public readonly ?string $mime,
        public readonly bool $inline,
        public readonly ?string $contentId,
    ) {
    }

    public static function fromPath(string $path, ?string $name = null, ?string $mime = null, bool $inline = false, ?string $contentId = null): self
    {
        return new self($path, null, $name ?? basename($path), $mime, $inline, $contentId);
    }

    public static function fromData(string $body, string $name, ?string $mime = null, bool $inline = false, ?string $contentId = null): self
    {
        return new self(null, $body, $name, $mime, $inline, $contentId);
    }

    /** Copy marked inline for the HTML body (keeps the given content-id). */
    public function asInline(?string $contentId = null): self
    {
        return new self($this->path, $this->body, $this->name, $this->mime, true, $contentId ?? $this->contentId);
    }

    /** Read the attachment's bytes (used by transports that need bodies). */
    public function contents(): string
    {
        if ($this->body !== null) {
            return $this->body;
        }

        if ($this->path === null) {
            throw new InvalidMessageException('The attachment has neither a path nor contents.');
        }

        try {
            $contents = @file_get_contents($this->path);
        } catch (Throwable $failure) {
            throw new InvalidMessageException(sprintf(
                'Attachment [%s] could not be read: %s',
                $this->name ?? $this->path,
                $failure->getMessage(),
            ), 0, $failure);
        }

        if ($contents === false) {
            throw new InvalidMessageException(sprintf(
                'Attachment [%s] could not be read from [%s]; check that the file exists and is readable.',
                $this->name ?? $this->path,
                $this->path,
            ));
        }

        return $contents;
    }
}
