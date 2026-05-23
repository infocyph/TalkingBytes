<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\ValueObject\HeaderBag;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmailPart;

final readonly class MimePartParser
{
    public function __construct(
        private HeaderParser $headerParser = new HeaderParser(),
        private TransferDecoder $transferDecoder = new TransferDecoder(),
    ) {}

    public function parse(string $rawPart): ParsedEmailPart
    {
        [$rawHeaders, $rawBody] = $this->splitRawMessage($rawPart);
        $headers = $this->headerParser->parse($rawHeaders);

        return $this->parseFromHeadersAndBody($headers, $rawBody);
    }

    public function parseFromHeadersAndBody(HeaderBag $headers, string $body): ParsedEmailPart
    {
        $contentTypeHeader = $headers->first('Content-Type') ?? 'text/plain';
        $transferEncoding = $headers->first('Content-Transfer-Encoding');
        $contentDisposition = $headers->first('Content-Disposition');

        $contentType = $this->headerValueToken($contentTypeHeader);
        $boundary = $this->headerParameter($contentTypeHeader, 'boundary');
        $charset = $this->headerParameter($contentTypeHeader, 'charset');
        $nameFromContentType = $this->headerParameter($contentTypeHeader, 'name');
        $disposition = $contentDisposition !== null ? $this->headerValueToken($contentDisposition) : null;
        $filename = $this->headerParameter($contentDisposition, 'filename')
            ?? $this->headerParameter($contentDisposition, 'filename*')
            ?? $nameFromContentType;

        $contentId = $headers->first('Content-ID');
        if ($contentId !== null) {
            $contentId = trim($contentId, '<>');
        }

        $children = [];
        $decodedBody = '';

        if (str_starts_with($contentType, 'multipart/') && $boundary !== null && $boundary !== '') {
            foreach ($this->splitMultipartBody($body, $boundary) as $partBody) {
                $children[] = $this->parse($partBody);
            }
        } else {
            $decodedBody = $this->transferDecoder->decode($body, $transferEncoding);
        }

        $isInline = $disposition === 'inline';

        return new ParsedEmailPart(
            $headers->asMap(),
            $contentType,
            $charset,
            $disposition,
            $filename,
            $contentId,
            $decodedBody,
            $isInline,
            $children,
        );
    }

    private function headerParameter(?string $value, string $parameter): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match(sprintf('/%s\*?=([^;]+)/i', preg_quote($parameter, '/')), $value, $matches) !== 1) {
            return null;
        }

        $parameterValue = trim($matches[1], " \t\n\r\0\x0B\"'");

        if (str_contains($parameterValue, "''")) {
            [, $parameterValue] = explode("''", $parameterValue, 2);
        }

        return rawurldecode($parameterValue);
    }

    private function headerValueToken(string $value): string
    {
        $token = trim(strtolower(explode(';', $value, 2)[0]));

        return $token !== '' ? $token : 'text/plain';
    }

    /**
     * @return list<string>
     */
    private function splitMultipartBody(string $body, string $boundary): array
    {
        $normalized = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $body));
        $startDelimiter = '--' . $boundary;
        $endDelimiter = $startDelimiter . '--';
        $lines = preg_split('/\r\n/', $normalized) ?: [];
        $parts = [];
        $buffer = [];
        $inPart = false;

        foreach ($lines as $line) {
            if ($line === $startDelimiter || $line === $endDelimiter) {
                if ($inPart && $buffer !== []) {
                    $parts[] = implode("\r\n", $buffer);
                    $buffer = [];
                }

                if ($line === $endDelimiter) {
                    break;
                }

                $inPart = true;

                continue;
            }

            if (!$inPart) {
                continue;
            }

            $buffer[] = $line;
        }

        if ($buffer !== []) {
            $parts[] = implode("\r\n", $buffer);
        }

        return $parts;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitRawMessage(string $raw): array
    {
        $normalized = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $raw));
        $parts = preg_split("/\r\n\r\n/", $normalized, 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
