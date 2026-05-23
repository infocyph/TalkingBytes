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

        $parts = [];
        $remaining = $headerLine;

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
}
