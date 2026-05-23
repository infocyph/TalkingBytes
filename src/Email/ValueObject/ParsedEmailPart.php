<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

final readonly class ParsedEmailPart
{
    /**
     * @param array<string, list<string>> $headers
     * @param list<ParsedEmailPart> $children
     */
    public function __construct(
        public array $headers,
        public string $contentType,
        public ?string $charset,
        public ?string $disposition,
        public ?string $filename,
        public ?string $contentId,
        public string $body,
        public bool $inline,
        public array $children = [],
    ) {}
}
