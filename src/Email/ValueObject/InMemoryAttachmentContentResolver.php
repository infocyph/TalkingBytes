<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use RuntimeException;

final readonly class InMemoryAttachmentContentResolver implements AttachmentContentResolver
{
    public function __construct(
        private string $contents,
    ) {}

    public function contents(): string
    {
        return $this->contents;
    }

    public function streamTo(mixed $stream): void
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('Attachment stream target must be a valid resource.');
        }

        $written = 0;
        $length = strlen($this->contents);

        while ($written < $length) {
            $chunk = substr($this->contents, $written);
            $bytes = fwrite($stream, $chunk);

            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Failed to stream attachment content.');
            }

            $written += $bytes;
        }
    }
}
