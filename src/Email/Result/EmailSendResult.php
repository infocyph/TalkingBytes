<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Result;

final readonly class EmailSendResult
{
    /**
     * @param list<string> $acceptedRecipients
     * @param array<string, string> $rejectedRecipients
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $transport,
        public ?string $messageId,
        public array $acceptedRecipients,
        public array $rejectedRecipients,
        public array $metadata = [],
    ) {}

    public function recipientCount(): int
    {
        return count($this->acceptedRecipients) + count($this->rejectedRecipients);
    }

    public function successful(): bool
    {
        return $this->acceptedRecipients !== [] && $this->rejectedRecipients === [];
    }
}
