<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Message;

final readonly class CommunicationResponse
{
    /**
     * @param array<string, string|string[]> $headers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ?int $statusCode,
        public mixed $body,
        public array $headers = [],
        public array $metadata = [],
    ) {}
}
