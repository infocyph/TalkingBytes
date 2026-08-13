<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Body;

final readonly class FormBody implements HttpBody
{
    private string $payload;

    /**
     * @param array<string, scalar|list<scalar>> $fields
     */
    public function __construct(array $fields)
    {
        $this->payload = http_build_query($fields);
    }

    public function contentType(): string
    {
        return 'application/x-www-form-urlencoded';
    }

    public function toCurlPayload(): string
    {
        return $this->payload;
    }
}
