<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\Config\EmailLimits;
use Infocyph\TalkingBytes\Email\ValueObject\HeaderBag;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmailPart;

final readonly class MimeParser
{
    private MimePartParser $partParser;

    public function __construct(?MimePartParser $partParser = null, EmailLimits $limits = new EmailLimits())
    {
        $this->partParser = $partParser ?? new MimePartParser(limits: $limits);
    }

    public function parse(HeaderBag $headers, string $body): ParsedEmailPart
    {
        return $this->partParser->parseFromHeadersAndBody($headers, $body);
    }
}
