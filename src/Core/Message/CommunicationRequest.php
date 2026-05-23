<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Message;

final readonly class CommunicationRequest
{
    /**
     * @param array<string, string|string[]> $headers
     * @param array<string, mixed> $options
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $transport,
        public mixed $payload,
        public array $headers = [],
        public array $options = [],
        public array $metadata = [],
    ) {}

    /**
     * @param array<string, string|string[]> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->transport, $this->payload, $headers, $this->options, $this->metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self($this->transport, $this->payload, $this->headers, $this->options, $metadata);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): self
    {
        return new self($this->transport, $this->payload, $this->headers, $options, $this->metadata);
    }
}
