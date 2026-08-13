<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Body;

interface HttpBody
{
    public function contentType(): string;

    /**
     * @return string|array<string, string|\CURLFile|\CURLStringFile>
     */
    public function toCurlPayload(): string|array;
}
