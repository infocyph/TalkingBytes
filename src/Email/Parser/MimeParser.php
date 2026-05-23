<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\ValueObject\HeaderBag;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmailPart;

final readonly class MimeParser
{
    public function __construct(private MimePartParser $partParser = new MimePartParser()) {}

    public function parse(HeaderBag $headers, string $body): ParsedEmailPart
    {
        return $this->partParser->parseFromHeadersAndBody($headers, $body);
    }
}
