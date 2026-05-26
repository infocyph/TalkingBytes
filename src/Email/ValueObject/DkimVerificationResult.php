<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

final readonly class DkimVerificationResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $valid,
        public ?string $domain = null,
        public ?string $selector = null,
        public ?string $reason = null,
        public array $metadata = [],
    ) {}
}
