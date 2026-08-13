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
        $message = $message->prepare();
        [$textBody, $htmlBody, $hasText, $hasHtml] = $this->extractBodies($message);
        [$inlineAttachments, $regularAttachments] = $this->splitAttachments($message->attachments());
        $bodyPart = $this->buildBodyPart($message, $textBody, $htmlBody, $hasText, $hasHtml);

        if ($inlineAttachments !== []) {
            $bodyPart = $this->wrapMultipart($message, $bodyPart, $inlineAttachments, 'related');
        }

        if ($regularAttachments !== []) {
            return $this->wrapMultipart($message, $bodyPart, $regularAttachments, 'mixed');
        }

        if ($bodyPart->contentTransferEncoding !== null) {
            return new MimeMessage($bodyPart->contentType, $bodyPart->body, $bodyPart->contentTransferEncoding);
        }

        return new MimeMessage($bodyPart->contentType, $bodyPart->body);
    }

    /**
     * @param callable(string):void $write
     */
    public function buildToStream(EmailMessage $message, callable $write): MimeMessage
    {
        $message = $message->prepare();
        [$textBody, $htmlBody, $hasText, $hasHtml] = $this->extractBodies($message);
        [$inlineAttachments, $regularAttachments] = $this->splitAttachments($message->attachments());
        $bodyPart = $this->buildBodyPart($message, $textBody, $htmlBody, $hasText, $hasHtml);

        if ($inlineAttachments === [] && $regularAttachments === []) {
            $write($bodyPart->body);

            if ($bodyPart->contentTransferEncoding !== null) {
                return new MimeMessage($bodyPart->contentType, '', $bodyPart->contentTransferEncoding);
            }

            return new MimeMessage($bodyPart->contentType, '');
        }

        $streamState = $this->initialStreamState($bodyPart);

        if ($inlineAttachments !== []) {
            $streamState = $this->wrapStreamState($message, $streamState, $inlineAttachments, 'related');
        }

        if ($regularAttachments !== []) {
            $streamState = $this->wrapStreamState($message, $streamState, $regularAttachments, 'mixed');
        }

        $streamState['writer']($write);

        return new MimeMessage($streamState['contentType'], '', $streamState['encoding']);
    }

    private function buildBodyPart(
        EmailMessage $message,
        string $textBody,
        string $htmlBody,
        bool $hasText,
        bool $hasHtml,
    ): MimeMessage {
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

        $alternativeBoundary = $message->mimeBoundary('alternative');
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
     * @return array{0:string,1:string,2:bool,3:bool}
     */
    private function extractBodies(EmailMessage $message): array
    {
        $textBody = $message->textBody();
        $htmlBody = $message->htmlBody();
        if ($textBody === '' && $htmlBody !== '') {
            $textBody = strip_tags($htmlBody);
        }

        return [$textBody, $htmlBody, $textBody !== '', $htmlBody !== ''];
    }

    /**
     * @return array{contentType:string,encoding:?ContentTransferEncoding,writer:callable(callable(string):void):void}
     */
    private function initialStreamState(MimeMessage $bodyPart): array
    {
        return [
            'contentType' => $bodyPart->contentType,
            'encoding' => $bodyPart->contentTransferEncoding,
            'writer' => static function (callable $streamWrite) use ($bodyPart): void {
                $streamWrite($bodyPart->body);
            },
        ];
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
    private function wrapMultipart(
        EmailMessage $message,
        MimeMessage $rootPart,
        array $attachments,
        string $multipartType,
    ): MimeMessage {
        $boundary = $message->mimeBoundary($multipartType);
        $body = sprintf(
            "--%s\r\n%s\r\n\r\n%s\r\n",
            $boundary,
            'Content-Type: ' . $rootPart->contentType,
            $rootPart->body,
        );

        foreach ($attachments as $attachment) {
            $body .= sprintf('--%s\r\n%s\r\n', $boundary, $this->attachmentEncoder->encode($attachment)->render());
        }

        $body .= sprintf('--%s--\r\n', $boundary);

        return new MimeMessage(
            sprintf('multipart/%s; boundary="%s"', $multipartType, $boundary),
            $body,
        );
    }

    /**
     * @param array{contentType:string,encoding:?ContentTransferEncoding,writer:callable(callable(string):void):void} $state
     * @param list<EmailAttachment> $attachments
     * @return array{contentType:string,encoding:?ContentTransferEncoding,writer:callable(callable(string):void):void}
     */
    private function wrapStreamState(
        EmailMessage $message,
        array $state,
        array $attachments,
        string $multipartType,
    ): array {
        $boundary = $message->mimeBoundary($multipartType);
        $previousContentType = $state['contentType'];
        $previousEncoding = $state['encoding'];
        $previousWriter = $state['writer'];

        return [
            'contentType' => sprintf('multipart/%s; boundary="%s"', $multipartType, $boundary),
            'encoding' => null,
            'writer' => function (callable $streamWrite) use (
                $boundary,
                $previousContentType,
                $previousEncoding,
                $previousWriter,
                $attachments,
            ): void {
                $this->writeMultipartBoundary($streamWrite, $boundary);
                $this->writePartHeaders($streamWrite, $previousContentType, $previousEncoding);
                $previousWriter($streamWrite);
                $streamWrite("\r\n");

                foreach ($attachments as $attachment) {
                    $this->writeMultipartBoundary($streamWrite, $boundary);
                    $this->attachmentEncoder->encodeToStream($attachment, $streamWrite, includeHeaders: true);
                    $streamWrite("\r\n");
                }

                $this->writeMultipartClosingBoundary($streamWrite, $boundary);
            },
        ];
    }

    /**
     * @param callable(string):void $write
     */
    private function writeMultipartBoundary(callable $write, string $boundary): void
    {
        $write(sprintf("--%s\r\n", $boundary));
    }

    /**
     * @param callable(string):void $write
     */
    private function writeMultipartClosingBoundary(callable $write, string $boundary): void
    {
        $write(sprintf("--%s--\r\n", $boundary));
    }

    /**
     * @param callable(string):void $write
     */
    private function writePartHeaders(
        callable $write,
        string $contentType,
        ?ContentTransferEncoding $encoding,
    ): void {
        $write('Content-Type: ' . $contentType . "\r\n");
        if ($encoding !== null) {
            $write('Content-Transfer-Encoding: ' . $encoding->value . "\r\n");
        }

        $write("\r\n");
    }
}
