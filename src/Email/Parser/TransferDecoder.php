<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\Exception\EmailParseException;

final class TransferDecoder
{
    public function decode(string $body, ?string $transferEncoding, ?int $maxDecodedBytes = null): string
    {
        $encoding = strtolower(trim((string) $transferEncoding));

        $decoded = match ($encoding) {
            'base64' => $this->decodeBase64($body, $maxDecodedBytes),
            'quoted-printable' => quoted_printable_decode($body),
            '7bit', '8bit', 'binary', '' => $body,
            default => $body,
        };

        if ($maxDecodedBytes !== null && strlen($decoded) > $maxDecodedBytes) {
            throw new EmailParseException(sprintf('Decoded MIME body exceeds limit (%d bytes).', $maxDecodedBytes));
        }

        return $decoded;
    }

    private function decodeBase64(string $body, ?int $maxDecodedBytes): string
    {
        $decoded = '';
        $quartet = '';
        $length = strlen($body);
        for ($index = 0; $index < $length; $index++) {
            $character = $body[$index];
            if (ctype_space($character)) {
                continue;
            }
            $quartet .= $character;
            if (strlen($quartet) < 4) {
                continue;
            }

            $chunk = base64_decode($quartet, true);
            if ($chunk === false) {
                throw new EmailParseException('MIME body contains invalid base64 data.');
            }
            $decoded .= $chunk;
            $quartet = '';
            if ($maxDecodedBytes !== null && strlen($decoded) > $maxDecodedBytes) {
                throw new EmailParseException(sprintf('Decoded MIME body exceeds limit (%d bytes).', $maxDecodedBytes));
            }
        }

        if ($quartet !== '') {
            $chunk = base64_decode($quartet, true);
            if ($chunk === false) {
                throw new EmailParseException('MIME body contains invalid base64 data.');
            }
            $decoded .= $chunk;
        }

        if ($maxDecodedBytes !== null && strlen($decoded) > $maxDecodedBytes) {
            throw new EmailParseException(sprintf('Decoded MIME body exceeds limit (%d bytes).', $maxDecodedBytes));
        }

        return $decoded;
    }
}
