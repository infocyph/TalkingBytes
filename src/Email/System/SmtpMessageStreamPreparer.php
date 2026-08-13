<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use Infocyph\TalkingBytes\Email\EmailMessage;
use RuntimeException;
use Throwable;

final readonly class SmtpMessageStreamPreparer
{
    public function __construct(
        private RawEmailBuilder $builder,
        private ?int $maxMessageBytes,
    ) {}

    /** @return array{stream:resource,sizeBytes:int,containsNonAscii:bool} */
    public function prepare(EmailMessage $message): array
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (!is_resource($stream)) {
            throw new RuntimeException('Unable to open temporary SMTP message stream.');
        }

        $containsNonAscii = false;

        try {
            $sizeBytes = $this->builder->buildToStream(
                $message,
                static function (string $chunk) use ($stream, &$containsNonAscii): void {
                    $containsNonAscii = $containsNonAscii || preg_match('/[^\x00-\x7F]/', $chunk) === 1;
                    self::writeChunk($stream, $chunk);
                },
                includeSubject: true,
                maxBytes: $this->maxMessageBytes,
            );
            rewind($stream);

            return ['stream' => $stream, 'sizeBytes' => $sizeBytes, 'containsNonAscii' => $containsNonAscii];
        } catch (Throwable $throwable) {
            fclose($stream);

            if ($this->isSizeFailure($throwable)) {
                throw new RuntimeException(sprintf(
                    'Email exceeds configured SMTP max message size %d bytes.',
                    $this->maxMessageBytes,
                ), previous: $throwable);
            }

            throw $throwable;
        }
    }

    /** @param resource $stream */
    private static function writeChunk($stream, string $chunk): void
    {
        $offset = 0;
        $length = strlen($chunk);
        while ($offset < $length) {
            $written = fwrite($stream, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write temporary SMTP message stream.');
            }
            $offset += $written;
        }
    }

    private function isSizeFailure(Throwable $throwable): bool
    {
        return $this->maxMessageBytes !== null
            && str_contains($throwable->getMessage(), 'Raw email size exceeds configured limit');
    }
}
