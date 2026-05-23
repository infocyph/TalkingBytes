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
        $headers = $this->normalizeLineEndings($this->headerBuilder->build($message, $mimeMessage, $includeSubject));
        $body = $this->normalizeLineEndings($mimeMessage->body);
        $raw = $headers . "\r\n\r\n" . $body;

        return new RawEmailMessage($headers, $body, $raw, strlen($raw));
    }

    private function normalizeLineEndings(string $value): string
    {
        return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $value));
    }
}
