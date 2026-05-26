<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Concurrent;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

final readonly class PoolResult
{
    /**
     * @param array<int|string, CommunicationResult> $results
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public array $results,
        public array $metadata = [],
    ) {}

    /**
     * @return array<int|string, CommunicationResult>
     */
    public function all(): array
    {
        return $this->results;
    }

    /**
     * @return array<int|string, CommunicationResult>
     */
    public function failed(): array
    {
        return array_filter($this->results, static fn(CommunicationResult $result): bool => !$result->successful);
    }

    public function failedCount(): int
    {
        return count($this->failed());
    }

    public function firstError(): ?CommunicationResult
    {
        foreach ($this->results as $result) {
            if (!$result->successful) {
                return $result;
            }
        }

        return null;
    }

    public function get(int|string $key): ?CommunicationResult
    {
        return $this->results[$key] ?? null;
    }

    /**
     * @return array<int|string, CommunicationResult>
     */
    public function successful(): array
    {
        return array_filter($this->results, static fn(CommunicationResult $result): bool => $result->successful);
    }

    public function successfulCount(): int
    {
        return count($this->successful());
    }
}
