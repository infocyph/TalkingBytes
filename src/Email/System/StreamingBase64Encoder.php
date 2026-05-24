<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

final class StreamingBase64Encoder
{
    /**
     * @param resource $input
     * @param callable(string):void $write
     */
    public function encode($input, callable $write, ?int $maxInputBytes = null): int
    {
        $carry = '';
        $bytesRead = 0;

        while (!feof($input)) {
            $chunk = fread($input, 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }

            $bytesRead += strlen($chunk);
            if ($maxInputBytes !== null && $bytesRead > $maxInputBytes) {
                throw new \RuntimeException(sprintf(
                    'Attachment stream exceeds max size (%d bytes).',
                    $maxInputBytes,
                ));
            }

            $data = $carry . $chunk;
            $completeLength = intdiv(strlen($data), 57) * 57;

            if ($completeLength > 0) {
                $write(chunk_split(base64_encode(substr($data, 0, $completeLength)), 76, "\r\n"));
            }

            $carry = substr($data, $completeLength);
        }

        if ($carry !== '') {
            $write(chunk_split(base64_encode($carry), 76, "\r\n"));
        }

        return $bytesRead;
    }
}
