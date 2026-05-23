<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

final class TransferDecoder
{
    public function decode(string $body, ?string $transferEncoding): string
    {
        $encoding = strtolower(trim((string) $transferEncoding));

        return match ($encoding) {
            'base64' => $this->decodeBase64($body),
            'quoted-printable' => quoted_printable_decode($body),
            '7bit', '8bit', 'binary', '' => $body,
            default => $body,
        };
    }

    private function decodeBase64(string $body): string
    {
        $decoded = base64_decode(preg_replace('/\s+/', '', $body) ?? $body, true);

        if ($decoded === false) {
            return $body;
        }

        return $decoded;
    }
}
