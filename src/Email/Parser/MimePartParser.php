<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\Config\EmailLimits;
use Infocyph\TalkingBytes\Email\Exception\EmailParseException;
use Infocyph\TalkingBytes\Email\ValueObject\HeaderBag;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmailPart;

final readonly class MimePartParser
{
    public function __construct(
        private HeaderParser $headerParser = new HeaderParser(),
        private TransferDecoder $transferDecoder = new TransferDecoder(),
        private CharsetDecoder $charsetDecoder = new CharsetDecoder(),
        private EmailLimits $limits = new EmailLimits(),
    ) {}

    public function parse(string $rawPart, ?string $partNumber = null): ParsedEmailPart
    {
        $partCount = 0;
        $decodedBytes = 0;

        return $this->parseRawPart($rawPart, $partNumber, 1, $partCount, $decodedBytes);
    }

    public function parseFromHeadersAndBody(HeaderBag $headers, string $body, ?string $partNumber = null): ParsedEmailPart
    {
        $partCount = 0;
        $decodedBytes = 0;

        return $this->parsePart($headers, $body, $partNumber, 1, $partCount, $decodedBytes);
    }

    private function contentId(HeaderBag $headers): ?string
    {
        $contentId = $headers->first('Content-ID');

        return $contentId === null ? null : trim($contentId, '<>');
    }

    private function countPart(int $depth, int &$partCount): void
    {
        $partCount++;
        if ($depth > $this->limits->maxMimeDepth) {
            throw new EmailParseException(sprintf('MIME nesting depth exceeds limit (%d).', $this->limits->maxMimeDepth));
        }
        if ($partCount > $this->limits->maxMimeParts) {
            throw new EmailParseException(sprintf('MIME part count exceeds limit (%d).', $this->limits->maxMimeParts));
        }
    }

    private function decodeParameterValue(string $value, bool $encoded): string
    {
        $decoded = $value;

        if ($encoded && preg_match('/^[^\']*\'[^\']*\'(.+)$/', $decoded, $matches) === 1) {
            $decoded = $matches[1];
        }

        if ($encoded) {
            $decoded = rawurldecode($decoded);
        }

        return trim($decoded, " \t\n\r\0\x0B\"");
    }

    /**
     * @return array<string, string>
     */
    private function headerParameters(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $segments = $this->splitHeaderParameters($value);
        $parameters = [];
        $continuations = [];

        foreach ($segments as $index => $segment) {
            if ($index === 0) {
                continue;
            }

            $this->parseParameterSegment($segment, $parameters, $continuations);
        }

        foreach ($continuations as $baseName => $parts) {
            ksort($parts);
            $joined = implode('', $parts);
            $parameters[$baseName] = $this->decodeParameterValue($joined, true);
        }

        return $parameters;
    }

    private function headerValueToken(string $value): string
    {
        $token = trim(strtolower(explode(';', $value, 2)[0]));

        return $token !== '' ? $token : 'text/plain';
    }

    /** @return array{0:string,1:list<ParsedEmailPart>} */
    private function parseBody(
        string $body,
        string $contentType,
        ?string $boundary,
        ?string $transferEncoding,
        ?string $charset,
        ?string $partNumber,
        int $depth,
        int &$partCount,
        int &$decodedBytes,
    ): array {
        if (!str_starts_with($contentType, 'multipart/') || $boundary === null || $boundary === '') {
            $remainingBytes = max(0, $this->limits->maxDecodedBodyBytes - $decodedBytes);
            $decodedBody = $this->transferDecoder->decode($body, $transferEncoding, $remainingBytes);
            $decodedBody = $this->charsetDecoder->toUtf8($decodedBody, $charset);
            $decodedBytes += strlen($decodedBody);
            if ($decodedBytes > $this->limits->maxDecodedBodyBytes) {
                throw new EmailParseException('Decoded MIME bodies exceed the configured aggregate limit.');
            }

            return [$decodedBody, []];
        }

        $children = [];
        foreach ($this->splitMultipartBody($body, $boundary) as $index => $partBody) {
            $childNumber = $partNumber === null
                ? (string) ($index + 1)
                : sprintf('%s.%d', $partNumber, $index + 1);
            $children[] = $this->parseRawPart($partBody, $childNumber, $depth + 1, $partCount, $decodedBytes);
        }

        return ['', $children];
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, array<int, string>> $continuations
     */
    private function parseParameterSegment(string $segment, array &$parameters, array &$continuations): void
    {
        $trimmed = trim($segment);
        if ($trimmed === '' || !str_contains($trimmed, '=')) {
            return;
        }

        [$rawName, $rawValue] = explode('=', $trimmed, 2);
        $name = strtolower(trim($rawName));
        $parameterValue = trim($rawValue, " \t\n\r\0\x0B\"");

        if (preg_match('/^(?<base>[a-z0-9_-]+)\*(?<index>\d+)\*?$/i', $name, $matches) === 1) {
            $base = strtolower($matches['base']);
            $partIndex = (int) $matches['index'];
            $continuations[$base] ??= [];
            $continuations[$base][$partIndex] = $parameterValue;

            return;
        }

        $isEncoded = str_ends_with($name, '*');
        $baseName = $isEncoded ? substr($name, 0, -1) : $name;
        $parameters[$baseName] = $this->decodeParameterValue($parameterValue, $isEncoded);
    }

    private function parsePart(
        HeaderBag $headers,
        string $body,
        ?string $partNumber,
        int $depth,
        int &$partCount,
        int &$decodedBytes,
    ): ParsedEmailPart {
        $this->countPart($depth, $partCount);

        $contentTypeHeader = $headers->first('Content-Type') ?? 'text/plain';
        $transferEncoding = $headers->first('Content-Transfer-Encoding');
        $contentDisposition = $headers->first('Content-Disposition');

        $contentType = $this->headerValueToken($contentTypeHeader);
        $contentTypeParams = $this->headerParameters($contentTypeHeader);
        $dispositionParams = $this->headerParameters($contentDisposition);

        $boundary = $contentTypeParams['boundary'] ?? null;
        $charset = $contentTypeParams['charset'] ?? null;
        $nameFromContentType = $contentTypeParams['name'] ?? null;
        $disposition = $contentDisposition !== null ? $this->headerValueToken($contentDisposition) : null;
        $filename = $dispositionParams['filename'] ?? $nameFromContentType;

        $contentId = $this->contentId($headers);
        [$decodedBody, $children] = $this->parseBody(
            $body,
            $contentType,
            $boundary,
            $transferEncoding,
            $charset,
            $partNumber,
            $depth,
            $partCount,
            $decodedBytes,
        );

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
            $partNumber,
            $children,
        );
    }

    private function parseRawPart(
        string $rawPart,
        ?string $partNumber,
        int $depth,
        int &$partCount,
        int &$decodedBytes,
    ): ParsedEmailPart {
        [$rawHeaders, $rawBody] = $this->splitRawMessage($rawPart);
        $headers = $this->headerParser->parse($rawHeaders);

        return $this->parsePart($headers, $rawBody, $partNumber, $depth, $partCount, $decodedBytes);
    }

    /**
     * @return list<string>
     */
    private function splitHeaderParameters(string $value): array
    {
        $segments = [];
        $buffer = '';
        $inQuotes = false;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ($character === '"' && ($index === 0 || $value[$index - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
            }

            if ($character === ';' && !$inQuotes) {
                $segments[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $character;
        }

        $segments[] = $buffer;

        return $segments;
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
