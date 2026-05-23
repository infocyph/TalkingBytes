<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Concurrent;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

final readonly class PoolResult
{
    /**
     * @param list<CommunicationResult> $results
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public array $results,
        public array $metadata = [],
    ) {}

    public function successfulCount(): int
    {
        return count(array_filter($this->results, static fn(CommunicationResult $result): bool => $result->successful));
    }
}
