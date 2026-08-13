<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Body;

use JsonException;

final readonly class JsonBody implements HttpBody
{
    private string $payload;

    public function __construct(mixed $value, int $flags = 0)
    {
        try {
            $this->payload = json_encode($value, JSON_THROW_ON_ERROR | $flags);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Failed to encode JSON request body.', previous: $exception);
        }
    }

    public function contentType(): string
    {
        return 'application/json';
    }

    public function toCurlPayload(): string
    {
        return $this->payload;
    }
}
