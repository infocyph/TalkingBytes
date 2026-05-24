<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use InvalidArgumentException;
use RuntimeException;

final readonly class ReceivedAttachment
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public ?string $contentId,
        public bool $inline,
        private AttachmentContentResolver $contentResolver,
    ) {}

    public function contentHash(string $algorithm = 'sha256'): string
    {
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new InvalidArgumentException(sprintf('Unsupported hash algorithm: %s', $algorithm));
        }

        return hash($algorithm, $this->contents());
    }

    public function contents(): string
    {
        return $this->contentResolver->contents();
    }

    public function extension(): ?string
    {
        $extension = strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : null;
    }

    public function isImage(): bool
    {
        return str_starts_with(strtolower($this->mimeType), 'image/');
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    public function safeFilename(string $fallback = 'attachment.bin'): string
    {
        $candidate = trim($this->filename);
        if ($candidate === '') {
            return $fallback;
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($candidate)) ?? '';
        $safe = trim($safe, '._');
        if ($safe === '') {
            return $fallback;
        }

        return $safe;
    }

    public function saveTo(string $path): void
    {
        if (file_put_contents($path, $this->contents(), LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Failed to write attachment to path: %s', $path));
        }
    }

    /**
     * @param resource $stream
     */
    public function streamTo(mixed $stream): void
    {
        $this->contentResolver->streamTo($stream);
    }
}
