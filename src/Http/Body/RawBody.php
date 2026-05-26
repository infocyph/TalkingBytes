<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Body;

final readonly class RawBody implements HttpBody
{
    public function __construct(
        private string $content,
        private string $type = 'text/plain',
    ) {}

    public function contentType(): string
    {
        return $this->type;
    }

    public function toCurlPayload(): string
    {
        return $this->content;
    }
}
