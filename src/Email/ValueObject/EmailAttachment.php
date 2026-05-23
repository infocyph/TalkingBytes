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
    ): self {
        self::assertDisposition($disposition);

        return new self(
            $name,
            $mimeType,
            strlen($content),
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

        return new self(
            $name ?? basename($path),
            is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream',
            $fileSize,
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
    ): self {
        self::assertDisposition($disposition);

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

        return new self($name, $mimeType, $sizeBytes, $disposition, $contentId, null, null, $stream);
    }

    public function isInline(): bool
    {
        return $this->disposition === 'inline';
    }

    public function readContent(): string
    {
        if ($this->content !== null) {
            return $this->content;
        }

        if ($this->path !== null) {
            $fileContent = file_get_contents($this->path);
            if ($fileContent === false) {
                throw new AttachmentException(sprintf('Unable to read attachment file: %s', $this->path));
            }

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

            return $data;
        }

        throw new AttachmentException(sprintf('Attachment source unavailable: %s', $this->name));
    }

    private static function assertDisposition(string $disposition): void
    {
        if (!in_array($disposition, ['attachment', 'inline'], true)) {
            throw new InvalidArgumentException(sprintf('Invalid attachment disposition: %s', $disposition));
        }
    }
}
