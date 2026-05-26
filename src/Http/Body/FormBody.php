<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Body;

final readonly class FormBody implements HttpBody
{
    /**
     * @param array<string, scalar|list<scalar>> $fields
     */
    public function __construct(private array $fields) {}

    public function contentType(): string
    {
        return 'application/x-www-form-urlencoded';
    }

    public function toCurlPayload(): string
    {
        return http_build_query($this->fields);
    }
}
