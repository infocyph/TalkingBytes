<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use Infocyph\TalkingBytes\Email\Parser\TransferDecoder;
use RuntimeException;

final readonly class ImapPartAttachmentContentResolver implements AttachmentContentResolver
{
    /**
     * @param callable(): string $rawPartFetcher
     */
    public function __construct(
        private string $partNumber,
        private ?string $transferEncoding,
        private mixed $rawPartFetcher,
        private TransferDecoder $transferDecoder = new TransferDecoder(),
    ) {}

    public function contents(): string
    {
        $fetcher = $this->rawPartFetcher;
        $raw = $fetcher();
        if ($raw === '') {
            throw new RuntimeException(sprintf('IMAP part %s returned empty content.', $this->partNumber));
        }

        return $this->transferDecoder->decode($raw, $this->transferEncoding);
    }

    public function streamTo(mixed $stream): void
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('Target stream is not a valid resource.');
        }

        $bytes = fwrite($stream, $this->contents());
        if ($bytes === false) {
            throw new RuntimeException('Failed to write attachment contents to target stream.');
        }
    }
}
