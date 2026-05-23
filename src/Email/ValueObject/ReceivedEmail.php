<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

final readonly class ReceivedEmail
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public array $headers,
        public string $body,
        public string $raw,
        public array $metadata = [],
    ) {}

    public static function fromParsedEmail(ParsedEmail $parsedEmail): self
    {
        $headers = [];

        foreach ($parsedEmail->headers as $name => $values) {
            $headers[$name] = $values[0] ?? '';
        }

        return new self(
            $headers,
            $parsedEmail->textBody ?? $parsedEmail->htmlBody ?? '',
            $parsedEmail->raw,
            $parsedEmail->metadata,
        );
    }

    public function header(string $name): ?string
    {
        $normalized = strtolower($name);

        foreach ($this->headers as $headerName => $value) {
            if (strtolower($headerName) === $normalized) {
                return $value;
            }
        }

        return null;
    }
}
