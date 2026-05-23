<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

final class HeaderFolder
{
    public function fold(string $headerLine, int $limit = 78): string
    {
        if (strlen($headerLine) <= $limit) {
            return $headerLine;
        }

        $separatorPos = strpos($headerLine, ':');
        if ($separatorPos === false) {
            return $this->foldGeneric($headerLine, $limit);
        }

        $name = substr($headerLine, 0, $separatorPos);
        $value = ltrim(substr($headerLine, $separatorPos + 1));

        if (strcasecmp($name, 'DKIM-Signature') === 0) {
            return $this->foldDkim($name, $value, $limit);
        }

        return $this->foldStructured($name, $value, $limit);
    }

    private function foldDkim(string $name, string $value, int $limit): string
    {
        $prefix = $name . ': ';
        $lines = [];
        $current = $prefix;
        $segments = preg_split('/;\s*/', trim($value)) ?: [];

        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }

            $piece = ($index > 0 ? '; ' : '') . $segment;
            if (strlen($current . $piece) <= $limit) {
                $current .= $piece;

                continue;
            }

            $lines[] = rtrim($current, '; ');
            $current = ' ' . ltrim($segment);
        }

        $lines[] = $current;

        if (!str_contains($lines[0], ':')) {
            $lines[0] = $prefix . ltrim($lines[0]);
        }

        return implode(";\r\n", $lines);
    }

    private function foldGeneric(string $value, int $limit): string
    {
        $parts = [];
        $remaining = $value;

        while (strlen($remaining) > $limit) {
            $chunk = substr($remaining, 0, $limit);
            $breakPos = strrpos($chunk, ' ');

            if ($breakPos === false || $breakPos < 1) {
                $breakPos = $limit;
            }

            $parts[] = substr($remaining, 0, $breakPos);
            $remaining = ltrim(substr($remaining, $breakPos));
        }

        $parts[] = $remaining;

        return implode("\r\n ", $parts);
    }

    private function foldStructured(string $name, string $value, int $limit): string
    {
        $prefix = $name . ': ';
        $lines = [];
        $current = $prefix;
        $tokens = preg_split('/([,\s]+)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (strlen($current . $token) <= $limit) {
                $current .= $token;

                continue;
            }

            $lines[] = rtrim($current);
            $current = ' ' . ltrim($token);
        }

        $lines[] = rtrim($current);

        return implode("\r\n", $lines);
    }
}
