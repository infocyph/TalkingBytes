<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use Closure;
use RuntimeException;

final readonly class ReceivedAttachment
{
    /**
     * @param Closure():string $contentResolver
     */
    public function __construct(
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public ?string $contentId,
        public bool $inline,
        private Closure $contentResolver,
    ) {}

    public function contents(): string
    {
        return ($this->contentResolver)();
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
        if (!is_resource($stream)) {
            throw new RuntimeException('Attachment stream target must be a valid resource.');
        }

        $contents = $this->contents();
        $written = 0;
        $length = strlen($contents);

        while ($written < $length) {
            $chunk = substr($contents, $written);
            $bytes = fwrite($stream, $chunk);

            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Failed to stream attachment content.');
            }

            $written += $bytes;
        }
    }
}
