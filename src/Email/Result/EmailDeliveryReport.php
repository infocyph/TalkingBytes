<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Result;

final readonly class EmailDeliveryReport
{
    /**
     * @param list<string> $acceptedRecipients
     * @param array<string, string> $rejectedRecipients
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $successful,
        public array $acceptedRecipients,
        public array $rejectedRecipients,
        public ?string $messageId = null,
        public ?string $error = null,
        public array $metadata = [],
    ) {}
}
