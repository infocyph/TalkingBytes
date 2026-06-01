<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Retry;

use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;
use InvalidArgumentException;

final readonly class WebhookRetryProfile
{
    /**
     * @param list<int> $retryStatuses
     */
    public function __construct(
        public int $attempts = 3,
        public int $baseDelayMs = 250,
        public array $retryStatuses = [408, 429, 500, 502, 503, 504],
        public ?int $maxRetryAfterSeconds = 30,
        public bool $retryOnTransportError = true,
    ) {
        if ($this->attempts < 1) {
            throw new InvalidArgumentException('Webhook retry attempts must be at least 1.');
        }

        if ($this->baseDelayMs < 0) {
            throw new InvalidArgumentException('Webhook retry baseDelayMs must be greater than or equal to 0.');
        }
    }

    public static function standard(int $attempts = 3, int $baseDelayMs = 250, int $maxRetryAfterSeconds = 30): self
    {
        return new self(
            attempts: $attempts,
            baseDelayMs: $baseDelayMs,
            retryStatuses: [408, 429, 500, 502, 503, 504],
            maxRetryAfterSeconds: $maxRetryAfterSeconds,
            retryOnTransportError: true,
        );
    }

    public function toHttpRetryPolicy(): HttpRetryPolicy
    {
        return new HttpRetryPolicy(
            maxAttempts: $this->attempts,
            baseDelayMs: $this->baseDelayMs,
            retryStatuses: $this->retryStatuses,
            maxRetryAfterSeconds: $this->maxRetryAfterSeconds,
            retryOnTransportError: $this->retryOnTransportError,
        );
    }
}
