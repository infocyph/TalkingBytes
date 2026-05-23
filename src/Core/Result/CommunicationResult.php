<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Result;

final readonly class CommunicationResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $successful,
        public ?int $statusCode = null,
        public ?string $error = null,
        public mixed $response = null,
        public array $metadata = [],
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failure(
        string $error,
        ?int $statusCode = null,
        mixed $response = null,
        array $metadata = [],
    ): self {
        return new self(false, $statusCode, $error, $response, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function success(
        ?int $statusCode = null,
        mixed $response = null,
        array $metadata = [],
    ): self {
        return new self(true, $statusCode, null, $response, $metadata);
    }
}
