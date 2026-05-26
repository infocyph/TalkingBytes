<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use Infocyph\TalkingBytes\Email\Enum\BounceType;

final readonly class BounceReport
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public BounceType $type,
        public ?string $recipient,
        public ?string $action,
        public ?string $status,
        public ?string $diagnosticCode,
        public ?string $remoteMta,
        public ?string $originalMessageId,
        public array $metadata = [],
    ) {}
}
