<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\ContentTransferEncoding;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAttachment;

final readonly class MimeMessageBuilder
{
    public function __construct(private AttachmentEncoder $attachmentEncoder = new AttachmentEncoder()) {}

    public function build(EmailMessage $message): MimeMessage
    {
        $textBody = $message->textBody();
        $htmlBody = $message->htmlBody();

        if ($textBody === '' && $htmlBody !== '') {
            $textBody = strip_tags($htmlBody);
        }

        $hasText = $textBody !== '';
        $hasHtml = $htmlBody !== '';

        [$inlineAttachments, $regularAttachments] = $this->splitAttachments($message->attachments());

        $bodyPart = $this->buildBodyPart($textBody, $htmlBody, $hasText, $hasHtml);

        if ($inlineAttachments !== []) {
            $bodyPart = $this->wrapRelated($bodyPart, $inlineAttachments);
        }

        if ($regularAttachments !== []) {
            return $this->wrapMixed($bodyPart, $regularAttachments);
        }

        if ($bodyPart->contentTransferEncoding !== null) {
            return new MimeMessage($bodyPart->contentType, $bodyPart->body, $bodyPart->contentTransferEncoding);
        }

        return new MimeMessage($bodyPart->contentType, $bodyPart->body);
    }

    private function buildBodyPart(string $textBody, string $htmlBody, bool $hasText, bool $hasHtml): MimeMessage
    {
        if ($hasText && !$hasHtml) {
            return new MimeMessage(
                'text/plain; charset=UTF-8',
                quoted_printable_encode($textBody),
                ContentTransferEncoding::QuotedPrintable,
            );
        }

        if (!$hasText && $hasHtml) {
            return new MimeMessage(
                'text/html; charset=UTF-8',
                quoted_printable_encode($htmlBody),
                ContentTransferEncoding::QuotedPrintable,
            );
        }

        $alternativeBoundary = MimeBoundary::generate();
        $parts = [
            $this->createTextPart($textBody),
            $this->createHtmlPart($htmlBody),
        ];

        return new MimeMessage(
            sprintf('multipart/alternative; boundary="%s"', $alternativeBoundary),
            $this->renderMultipart($alternativeBoundary, $parts),
        );
    }

    private function createHtmlPart(string $htmlBody): MimePart
    {
        return new MimePart(
            [
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: ' . ContentTransferEncoding::QuotedPrintable->value,
            ],
            quoted_printable_encode($htmlBody),
        );
    }

    private function createTextPart(string $textBody): MimePart
    {
        return new MimePart(
            [
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: ' . ContentTransferEncoding::QuotedPrintable->value,
            ],
            quoted_printable_encode($textBody),
        );
    }

    /**
     * @param list<MimePart> $parts
     */
    private function renderMultipart(string $boundary, array $parts): string
    {
        $body = '';
        foreach ($parts as $part) {
            $body .= sprintf('--%s\r\n%s\r\n', $boundary, $part->render());
        }

        return $body . sprintf('--%s--\r\n', $boundary);
    }

    /**
     * @param list<EmailAttachment> $attachments
     *
     * @return array{0:list<EmailAttachment>,1:list<EmailAttachment>}
     */
    private function splitAttachments(array $attachments): array
    {
        $inline = [];
        $regular = [];

        foreach ($attachments as $attachment) {
            if ($attachment->isInline()) {
                $inline[] = $attachment;

                continue;
            }

            $regular[] = $attachment;
        }

        return [$inline, $regular];
    }

    /**
     * @param list<EmailAttachment> $attachments
     */
    private function wrapMixed(MimeMessage $rootPart, array $attachments): MimeMessage
    {
        $mixedBoundary = MimeBoundary::generate();
        $body = sprintf(
            "--%s\r\n%s\r\n\r\n%s\r\n",
            $mixedBoundary,
            'Content-Type: ' . $rootPart->contentType,
            $rootPart->body,
        );

        foreach ($attachments as $attachment) {
            $body .= sprintf('--%s\r\n%s\r\n', $mixedBoundary, $this->attachmentEncoder->encode($attachment)->render());
        }

        $body .= sprintf('--%s--\r\n', $mixedBoundary);

        return new MimeMessage(
            sprintf('multipart/mixed; boundary="%s"', $mixedBoundary),
            $body,
        );
    }

    /**
     * @param list<EmailAttachment> $inlineAttachments
     */
    private function wrapRelated(MimeMessage $bodyPart, array $inlineAttachments): MimeMessage
    {
        $relatedBoundary = MimeBoundary::generate();
        $body = sprintf(
            "--%s\r\n%s\r\n\r\n%s\r\n",
            $relatedBoundary,
            'Content-Type: ' . $bodyPart->contentType,
            $bodyPart->body,
        );

        foreach ($inlineAttachments as $attachment) {
            $body .= sprintf('--%s\r\n%s\r\n', $relatedBoundary, $this->attachmentEncoder->encode($attachment)->render());
        }

        $body .= sprintf('--%s--\r\n', $relatedBoundary);

        return new MimeMessage(
            sprintf('multipart/related; boundary="%s"', $relatedBoundary),
            $body,
        );
    }
}
