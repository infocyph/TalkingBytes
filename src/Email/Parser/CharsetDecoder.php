<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

final readonly class CharsetDecoder
{
    public function __construct(private ?string $fallbackCharset = null) {}

    public function toUtf8(string $value, ?string $charset): string
    {
        $normalized = strtoupper(trim((string) $charset));
        if ($value === '' || $normalized === '' || $normalized === 'UTF-8') {
            return $value;
        }

        $candidates = $this->charsetCandidates($normalized);
        if ($this->fallbackCharset !== null && trim($this->fallbackCharset) !== '') {
            $fallback = strtoupper(trim($this->fallbackCharset));
            if (!in_array($fallback, $candidates, true)) {
                $candidates[] = $fallback;
            }
        }

        foreach ($candidates as $candidate) {
            $converted = $this->convertWithMb($value, $candidate);
            if ($converted !== null) {
                return $converted;
            }

            $converted = $this->convertWithIconv($value, $candidate);
            if ($converted !== null) {
                return $converted;
            }
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function charsetCandidates(string $charset): array
    {
        return match ($charset) {
            'US-ASCII', 'ASCII' => ['ASCII', 'US-ASCII'],
            'ISO-8859-1', 'LATIN1', 'ISO8859-1' => ['ISO-8859-1', 'LATIN1'],
            'ISO-8859-15', 'LATIN9', 'ISO8859-15' => ['ISO-8859-15', 'LATIN9'],
            'WINDOWS-1252', 'CP1252' => ['WINDOWS-1252', 'CP1252'],
            'CP850', 'IBM850' => ['CP850', 'IBM850'],
            'KOI8-R', 'KOI8R' => ['KOI8-R', 'KOI8R'],
            'GB18030' => ['GB18030', 'GBK'],
            'BIG5', 'BIG-5' => ['BIG5', 'BIG-5'],
            'SHIFT_JIS', 'SHIFT-JIS', 'SJIS' => ['SHIFT_JIS', 'SJIS'],
            default => [$charset],
        };
    }

    private function convertWithIconv(string $value, string $charset): ?string
    {
        if (!function_exists('iconv')) {
            return null;
        }

        // "X-*" vendor charset labels regularly trigger runtime warnings in iconv.
        if (preg_match('/^X-/i', $charset) === 1) {
            return null;
        }

        $previous = set_error_handler(static fn(): bool => true);

        try {
            $converted = iconv($charset, 'UTF-8//IGNORE', $value);
        } finally {
            if ($previous !== null) {
                set_error_handler($previous);
            } else {
                restore_error_handler();
            }
        }

        return is_string($converted) ? $converted : null;
    }

    private function convertWithMb(string $value, string $charset): ?string
    {
        if (!function_exists('mb_convert_encoding')) {
            return null;
        }

        try {
            $converted = mb_convert_encoding($value, 'UTF-8', $charset);

            return is_string($converted) ? $converted : null;
        } catch (\ValueError) {
            return null;
        }
    }
}
