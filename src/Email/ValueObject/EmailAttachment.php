<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use Infocyph\TalkingBytes\Email\Exception\AttachmentException;
use InvalidArgumentException;

final readonly class EmailAttachment
{
    private const int DEFAULT_MAX_SIZE_BYTES = 26214400;

    /**
     * @param resource|null $stream
     */
    private function __construct(
        public string $name,
        public string $mimeType,
        public int $sizeBytes,
        private int $maxSizeBytes,
        public string $disposition = 'attachment',
        public ?string $contentId = null,
        public ?string $path = null,
        public ?string $content = null,
        public mixed $stream = null,
    ) {}

    public static function fromData(
        string $content,
        string $name,
        string $mimeType = 'application/octet-stream',
        string $disposition = 'attachment',
        ?string $contentId = null,
        int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES,
    ): self {
        self::assertDisposition($disposition);
        self::assertName($name);
        self::assertMimeType($mimeType);
        self::assertContentId($contentId);
        self::assertMaxSize($maxSizeBytes);

        $size = strlen($content);
        if ($size > $maxSizeBytes) {
            throw new AttachmentException(
                sprintf('Attachment data exceeds max size (%d bytes): %s', $maxSizeBytes, $name),
            );
        }

        return new self(
            $name,
            $mimeType,
            $size,
            $maxSizeBytes,
            $disposition,
            $contentId,
            null,
            $content,
        );
    }

    public static function fromPath(
        string $path,
        ?string $name = null,
        int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES,
        string $disposition = 'attachment',
        ?string $contentId = null,
    ): self {
        self::assertDisposition($disposition);
        self::assertMaxSize($maxSizeBytes);

        if (!is_file($path)) {
            throw new AttachmentException(sprintf('Attachment file not found: %s', $path));
        }

        if (!is_readable($path)) {
            throw new AttachmentException(sprintf('Attachment file is not readable: %s', $path));
        }

        $fileSize = filesize($path);
        if ($fileSize === false) {
            throw new AttachmentException(sprintf('Unable to determine attachment size: %s', $path));
        }

        if ($fileSize > $maxSizeBytes) {
            throw new AttachmentException(
                sprintf('Attachment exceeds max size (%d bytes): %s', $maxSizeBytes, $path),
            );
        }

        $mimeType = mime_content_type($path);
        $resolvedName = $name ?? basename($path);
        self::assertName($resolvedName);
        $resolvedMimeType = is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
        self::assertMimeType($resolvedMimeType);
        self::assertContentId($contentId);

        return new self(
            $resolvedName,
            $resolvedMimeType,
            $fileSize,
            $maxSizeBytes,
            $disposition,
            $contentId,
            $path,
        );
    }

    /**
     * @param resource $stream
     */
    public static function fromStream(
        mixed $stream,
        string $name,
        string $mimeType = 'application/octet-stream',
        string $disposition = 'attachment',
        ?string $contentId = null,
        int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES,
    ): self {
        self::assertDisposition($disposition);
        self::assertName($name);
        self::assertMimeType($mimeType);
        self::assertContentId($contentId);
        self::assertMaxSize($maxSizeBytes);

        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Attachment stream must be a valid resource.');
        }

        $meta = stream_get_meta_data($stream);
        $sizeBytes = 0;

        $uri = $meta['uri'] ?? null;
        if (is_string($uri) && is_file($uri)) {
            $size = filesize($uri);
            if ($size !== false) {
                $sizeBytes = $size;
            }
        }

        if ($sizeBytes > $maxSizeBytes) {
            throw new AttachmentException(
                sprintf('Attachment stream exceeds max size (%d bytes): %s', $maxSizeBytes, $name),
            );
        }

        return new self($name, $mimeType, $sizeBytes, $maxSizeBytes, $disposition, $contentId, null, null, $stream);
    }

    public function isInline(): bool
    {
        return $this->disposition === 'inline';
    }

    public function readContent(): string
    {
        if ($this->content !== null) {
            $this->assertReadSizeWithinLimit(strlen($this->content));

            return $this->content;
        }

        if ($this->path !== null) {
            $fileContent = file_get_contents($this->path);
            if ($fileContent === false) {
                throw new AttachmentException(sprintf('Unable to read attachment file: %s', $this->path));
            }

            $this->assertReadSizeWithinLimit(strlen($fileContent));

            return $fileContent;
        }

        if (is_resource($this->stream)) {
            $meta = stream_get_meta_data($this->stream);

            if ($meta['seekable'] === true) {
                rewind($this->stream);
            }

            $data = stream_get_contents($this->stream);
            if ($data === false) {
                throw new AttachmentException(sprintf('Unable to read attachment stream: %s', $this->name));
            }

            $this->assertReadSizeWithinLimit(strlen($data));

            return $data;
        }

        throw new AttachmentException(sprintf('Attachment source unavailable: %s', $this->name));
    }

    private static function assertContentId(?string $contentId): void
    {
        if ($contentId === null) {
            return;
        }

        if ($contentId === '' || str_contains($contentId, "\r") || str_contains($contentId, "\n") || str_contains($contentId, "\0")) {
            throw new InvalidArgumentException('Attachment contentId must not be empty or contain control characters.');
        }
    }

    private static function assertDisposition(string $disposition): void
    {
        if (!in_array($disposition, ['attachment', 'inline'], true)) {
            throw new InvalidArgumentException(sprintf('Invalid attachment disposition: %s', $disposition));
        }
    }

    private static function assertMaxSize(int $maxSizeBytes): void
    {
        if ($maxSizeBytes < 1) {
            throw new InvalidArgumentException('Attachment max size must be greater than zero.');
        }
    }

    private static function assertMimeType(string $mimeType): void
    {
        if (preg_match('/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/', $mimeType) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid attachment MIME type: %s', $mimeType));
        }
    }

    private static function assertName(string $name): void
    {
        if ($name === '' || str_contains($name, "\r") || str_contains($name, "\n") || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Attachment name must not be empty or contain control characters.');
        }
    }

    private function assertReadSizeWithinLimit(int $size): void
    {
        if ($size <= $this->maxSizeBytes) {
            return;
        }

        throw new AttachmentException(
            sprintf('Attachment exceeds max size (%d bytes): %s', $this->maxSizeBytes, $this->name),
        );
    }
}
