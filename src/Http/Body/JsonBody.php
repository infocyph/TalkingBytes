<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Body;

use JsonException;

final readonly class JsonBody implements HttpBody
{
    public function __construct(
        private mixed $value,
        private int $flags = 0,
    ) {}

    public function contentType(): string
    {
        return 'application/json';
    }

    public function toCurlPayload(): string
    {
        try {
            return json_encode($this->value, JSON_THROW_ON_ERROR | $this->flags);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Failed to encode JSON request body.', previous: $exception);
        }
    }
}
