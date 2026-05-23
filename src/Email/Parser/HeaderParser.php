<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use DateTimeImmutable;
use Infocyph\TalkingBytes\Email\ValueObject\HeaderBag;

final class HeaderParser
{
    public function parse(string $headerBlock): HeaderBag
    {
        $lines = preg_split('/\r\n/', $this->normalizeLineEndings($headerBlock)) ?: [];
        $unfolded = $this->unfold($lines);
        $headers = [];

        foreach ($unfolded as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $normalizedName = strtolower(trim($name));

            if (!array_key_exists($normalizedName, $headers)) {
                $headers[$normalizedName] = [];
            }

            $headers[$normalizedName][] = $this->decodeHeaderValue(trim($value));
        }

        return new HeaderBag($headers, $unfolded);
    }

    public function parseDate(?string $headerValue): ?DateTimeImmutable
    {
        if ($headerValue === null || trim($headerValue) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($headerValue);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    public function parseReferences(?string $headerValue): array
    {
        if ($headerValue === null || trim($headerValue) === '') {
            return [];
        }

        if (preg_match_all('/<[^>]+>/', $headerValue, $matches) > 0) {
            return $matches[0];
        }

        $parts = preg_split('/\s+/', trim($headerValue)) ?: [];
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $normalized[] = str_starts_with($part, '<') ? $part : sprintf('<%s>', trim($part, '<>'));
        }

        return $normalized;
    }

    private function decodeHeaderValue(string $value): string
    {
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        if ($decoded === false) {
            return $value;
        }

        return $decoded;
    }

    private function normalizeLineEndings(string $value): string
    {
        return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $value));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function unfold(array $lines): array
    {
        $unfolded = [];

        foreach ($lines as $line) {
            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                $last = array_key_last($unfolded);
                if ($last !== null) {
                    $unfolded[$last] .= ' ' . ltrim($line);
                }

                continue;
            }

            $unfolded[] = $line;
        }

        return $unfolded;
    }
}
