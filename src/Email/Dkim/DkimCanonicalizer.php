<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

final class DkimCanonicalizer
{
    public function canonicalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);

        foreach ($lines as &$line) {
            $line = rtrim(preg_replace('/[ \t]+/', ' ', $line) ?? $line, ' ');
        }
        unset($line);

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    public function canonicalizeHeader(string $name, string $value): string
    {
        $normalizedName = strtolower(trim($name));
        $normalizedValue = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);

        return sprintf('%s:%s', $normalizedName, $normalizedValue);
    }
}
