<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use Infocyph\TalkingBytes\Email\EmailMessage;

final readonly class RawEmailBuilder
{
    public function __construct(
        private MimeMessageBuilder $mimeMessageBuilder = new MimeMessageBuilder(),
        private EmailHeaderBuilder $headerBuilder = new EmailHeaderBuilder(),
    ) {}

    public function build(EmailMessage $message, bool $includeSubject = true): RawEmailMessage
    {
        $mimeMessage = $this->mimeMessageBuilder->build($message);
        $headers = $this->headerBuilder->build($message, $mimeMessage, $includeSubject);
        $raw = $headers . "\r\n\r\n" . $mimeMessage->body;

        return new RawEmailMessage($headers, $mimeMessage->body, $raw, strlen($raw));
    }
}
