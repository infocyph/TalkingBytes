<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

final class FilenameEncoder
{
    /**
     * @return array{fallback:string,star:string}
     */
    public function encode(string $filename): array
    {
        $sanitized = str_replace(["\r", "\n"], '', $filename);
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $sanitized) ?? $sanitized;
        $fallback = addcslashes($fallback, '"\\');

        return [
            'fallback' => $fallback,
            'star' => rawurlencode($sanitized),
        ];
    }
}
