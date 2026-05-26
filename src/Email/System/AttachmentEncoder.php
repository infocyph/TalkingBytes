<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use Infocyph\TalkingBytes\Email\Enum\ContentTransferEncoding;
use Infocyph\TalkingBytes\Email\Exception\AttachmentException;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAttachment;
use RuntimeException;

final readonly class AttachmentEncoder
{
    public function __construct(
        private FilenameEncoder $filenameEncoder = new FilenameEncoder(),
        private StreamingBase64Encoder $streamingBase64Encoder = new StreamingBase64Encoder(),
    ) {}

    public function encode(EmailAttachment $attachment): MimePart
    {
        $encodedContent = '';
        $this->encodeToStream(
            $attachment,
            static function (string $chunk) use (&$encodedContent): void {
                $encodedContent .= $chunk;
            },
            includeHeaders: false,
        );

        return new MimePart($this->buildHeaders($attachment), trim($encodedContent));
    }

    /**
     * @param callable(string):void $write
     */
    public function encodeToStream(EmailAttachment $attachment, callable $write, bool $includeHeaders = true): void
    {
        if ($includeHeaders) {
            $write(implode("\r\n", $this->buildHeaders($attachment)) . "\r\n\r\n");
        }

        ['stream' => $stream, 'owned' => $ownedStream] = $this->openReadableStream($attachment);

        try {
            $this->streamingBase64Encoder->encode(
                $stream,
                static function (string $chunk) use ($write): void {
                    if ($chunk !== '') {
                        $write($chunk);
                    }
                },
                $attachment->maxSizeBytes(),
            );
        } catch (RuntimeException $exception) {
            throw new AttachmentException(sprintf(
                'Unable to encode attachment "%s": %s',
                $attachment->name,
                $exception->getMessage(),
            ), previous: $exception);
        } finally {
            if ($ownedStream) {
                fclose($stream);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function buildHeaders(EmailAttachment $attachment): array
    {
        $filename = $this->filenameEncoder->encode($attachment->name);
        $headers = [
            sprintf(
                'Content-Type: %s; name="%s"; name*=UTF-8\'\'%s',
                $attachment->mimeType,
                $filename['fallback'],
                $filename['star'],
            ),
            sprintf(
                'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
                $attachment->disposition,
                $filename['fallback'],
                $filename['star'],
            ),
            'Content-Transfer-Encoding: ' . ContentTransferEncoding::Base64->value,
        ];

        if ($attachment->isInline() && $attachment->contentId !== null && $attachment->contentId !== '') {
            $headers[] = sprintf('Content-ID: <%s>', trim($attachment->contentId, '<>'));
        }

        return $headers;
    }

    /**
     * @return array{stream:resource,owned:bool}
     */
    private function openContentBackedStream(string $content): array
    {
        $stream = fopen('php://temp', 'w+b');
        if (!is_resource($stream)) {
            throw new RuntimeException('Unable to open temporary stream for attachment encoding.');
        }

        if (fwrite($stream, $content) === false) {
            fclose($stream);

            throw new RuntimeException('Unable to write attachment content to temporary stream.');
        }

        rewind($stream);

        return ['stream' => $stream, 'owned' => true];
    }

    /**
     * @return array{stream:resource,owned:bool}
     */
    private function openReadableStream(EmailAttachment $attachment): array
    {
        if (is_resource($attachment->stream)) {
            $meta = stream_get_meta_data($attachment->stream);

            if ($meta['seekable'] === true) {
                rewind($attachment->stream);
            }

            return ['stream' => $attachment->stream, 'owned' => false];
        }

        if ($attachment->path !== null) {
            $stream = fopen($attachment->path, 'rb');
            if (!is_resource($stream)) {
                throw new AttachmentException(sprintf('Unable to open attachment file: %s', $attachment->path));
            }

            return ['stream' => $stream, 'owned' => true];
        }

        if ($attachment->content !== null) {
            return $this->openContentBackedStream($attachment->content);
        }

        throw new AttachmentException(sprintf('Attachment source unavailable: %s', $attachment->name));
    }
}
