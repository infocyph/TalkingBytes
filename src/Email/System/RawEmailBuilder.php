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

    /**
     * @param callable(string):void $write
     */
    public function buildToStream(
        EmailMessage $message,
        callable $write,
        bool $includeSubject = true,
        ?int $maxBytes = null,
    ): int {
        $bodyStream = fopen('php://temp', 'w+b');
        if (!is_resource($bodyStream)) {
            throw new \RuntimeException('Unable to open temporary stream for raw email body.');
        }

        $bodySizeBytes = 0;

        try {
            $mimeMessage = $this->mimeMessageBuilder->buildToStream(
                $message,
                function (string $chunk) use (&$bodySizeBytes, $bodyStream): void {
                    $normalizedChunk = $this->normalizeLineEndings($chunk);
                    if ($normalizedChunk === '') {
                        return;
                    }

                    $written = fwrite($bodyStream, $normalizedChunk);
                    if ($written === false || $written !== strlen($normalizedChunk)) {
                        throw new \RuntimeException('Unable to write temporary raw email body chunk.');
                    }

                    $bodySizeBytes += $written;
                },
            );
            $headers = $this->normalizeLineEndings($this->headerBuilder->build($message, $mimeMessage, $includeSubject));
        } catch (\Throwable $exception) {
            fclose($bodyStream);

            throw $exception;
        }

        $sizeBytes = 0;
        $this->writeWithLimit($headers, $write, $sizeBytes, $maxBytes);
        $this->writeWithLimit("\r\n\r\n", $write, $sizeBytes, $maxBytes);

        rewind($bodyStream);
        while (!feof($bodyStream)) {
            $chunk = fread($bodyStream, 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }

            $this->writeWithLimit($chunk, $write, $sizeBytes, $maxBytes);
        }

        fclose($bodyStream);

        return $sizeBytes;
    }

    /**
     * @return array{sizeBytes:int,containsNonAscii:bool,messageId:?string}
     */
    public function inspect(EmailMessage $message, bool $includeSubject = true): array
    {
        $sizeBytes = 0;
        $containsNonAscii = false;

        $this->buildToStream(
            $message,
            static function (string $chunk) use (&$sizeBytes, &$containsNonAscii): void {
                $sizeBytes += strlen($chunk);

                if (!$containsNonAscii && preg_match('/[^\x00-\x7F]/', $chunk) === 1) {
                    $containsNonAscii = true;
                }
            },
            $includeSubject,
        );

        return [
            'sizeBytes' => $sizeBytes,
            'containsNonAscii' => $containsNonAscii,
            'messageId' => $this->headerBuilder->resolveMessageId($message),
        ];
    }

    private function normalizeLineEndings(string $value): string
    {
        return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $value));
    }

    /**
     * @param callable(string):void $write
     */
    private function writeWithLimit(string $chunk, callable $write, int &$sizeBytes, ?int $maxBytes): void
    {
        if ($chunk === '') {
            return;
        }

        $nextSize = $sizeBytes + strlen($chunk);
        if ($maxBytes !== null && $nextSize > $maxBytes) {
            throw new \RuntimeException(sprintf(
                'Raw email size exceeds configured limit (%d bytes).',
                $maxBytes,
            ));
        }

        $write($chunk);
        $sizeBytes = $nextSize;
    }
}
