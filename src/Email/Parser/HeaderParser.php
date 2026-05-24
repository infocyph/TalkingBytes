<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use DateTimeImmutable;
use Infocyph\TalkingBytes\Email\Exception\EmailParseException;
use Infocyph\TalkingBytes\Email\ValueObject\HeaderBag;

final readonly class HeaderParser
{
    public const string MODE_STRICT = 'strict';

    public const string MODE_TOLERANT = 'tolerant';

    public function __construct(private string $mode = self::MODE_TOLERANT)
    {
        if (!in_array($this->mode, [self::MODE_STRICT, self::MODE_TOLERANT], true)) {
            throw new EmailParseException(sprintf('Invalid header parser mode: %s', $this->mode));
        }
    }

    public function parse(string $headerBlock, ?string $mode = null): HeaderBag
    {
        $mode ??= $this->mode;
        $lines = preg_split('/\r\n/', $this->normalizeLineEndings($headerBlock)) ?: [];
        $unfolded = $this->unfold($lines, $mode);
        $headers = [];

        foreach ($unfolded as $line) {
            if ($line === '') {
                continue;
            }

            if (!str_contains($line, ':')) {
                if ($mode === self::MODE_STRICT) {
                    throw new EmailParseException(sprintf('Malformed header line: %s', $line));
                }

                $headers['x-invalid-header'] ??= [];
                $headers['x-invalid-header'][] = $line;

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

    private static function convertToUtf8(string $charset, string $value): string|false
    {
        set_error_handler(
            static fn() => true,
        );

        try {
            return iconv($charset, 'UTF-8//IGNORE', $value);
        } finally {
            restore_error_handler();
        }
    }

    private function decodeEncodedWordsFallback(string $value): string
    {
        return (string) preg_replace_callback(
            '/=\?([^?]+)\?([bqBQ])\?([^?]+)\?=/',
            static function (array $matches): string {
                $charset = strtoupper($matches[1]);
                $encoding = strtoupper($matches[2]);
                $payload = $matches[3];

                $decoded = $encoding === 'B'
                    ? base64_decode($payload, true)
                    : quoted_printable_decode(str_replace('_', ' ', $payload));

                if ($decoded === false) {
                    return $matches[0];
                }

                if ($charset === 'UTF-8' || $charset === 'US-ASCII') {
                    return $decoded;
                }

                $converted = self::convertToUtf8($charset, $decoded);

                return is_string($converted) && $converted !== '' ? $converted : $decoded;
            },
            $value,
        );
    }

    private function decodeHeaderValue(string $value): string
    {
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        if ($decoded !== false) {
            return $decoded;
        }

        return $this->decodeEncodedWordsFallback($value);
    }

    private function normalizeLineEndings(string $value): string
    {
        return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $value));
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function unfold(array $lines, string $mode): array
    {
        $unfolded = [];

        foreach ($lines as $line) {
            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                $last = array_key_last($unfolded);
                if ($last !== null) {
                    $unfolded[$last] .= ' ' . ltrim($line);
                } elseif ($mode === self::MODE_STRICT) {
                    throw new EmailParseException(sprintf('Malformed folded header line: %s', $line));
                } else {
                    $unfolded[] = ltrim($line);
                }

                continue;
            }

            $unfolded[] = $line;
        }

        return $unfolded;
    }
}
