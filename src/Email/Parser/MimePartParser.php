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
        private CharsetDecoder $charsetDecoder = new CharsetDecoder(),
    ) {}

    public function parse(string $rawPart, ?string $partNumber = null): ParsedEmailPart
    {
        [$rawHeaders, $rawBody] = $this->splitRawMessage($rawPart);
        $headers = $this->headerParser->parse($rawHeaders);

        return $this->parseFromHeadersAndBody($headers, $rawBody, $partNumber);
    }

    public function parseFromHeadersAndBody(HeaderBag $headers, string $body, ?string $partNumber = null): ParsedEmailPart
    {
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

        $contentId = $headers->first('Content-ID');
        if ($contentId !== null) {
            $contentId = trim($contentId, '<>');
        }

        $children = [];
        $decodedBody = '';

        if (str_starts_with($contentType, 'multipart/') && $boundary !== null && $boundary !== '') {
            foreach ($this->splitMultipartBody($body, $boundary) as $index => $partBody) {
                $childNumber = $partNumber === null
                    ? (string) ($index + 1)
                    : sprintf('%s.%d', $partNumber, $index + 1);
                $children[] = $this->parse($partBody, $childNumber);
            }
        } else {
            $decodedBody = $this->transferDecoder->decode($body, $transferEncoding);
            $decodedBody = $this->charsetDecoder->toUtf8($decodedBody, $charset);
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
            $partNumber,
            $children,
        );
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
