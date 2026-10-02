<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Core\Support\StreamWaiter;
use RuntimeException;

final readonly class SmtpIoRuntime
{
    public function __construct(
        private ?CancellationSignal $cancellation = null,
        private ?OperationDeadline $operationDeadline = null,
        private ?StreamWaiter $streamWaiter = null,
    ) {}

    public function assertExecutionAllowed(?OperationDeadline $deadline = null): void
    {
        if ($this->cancellation?->isRequested() === true) {
            throw new RuntimeException('SMTP operation cancelled.');
        }
        if ($this->operationDeadline?->expired() === true) {
            throw new RuntimeException('SMTP operation deadline exceeded.');
        }
        if ($deadline?->expired() === true) {
            throw new RuntimeException('SMTP command deadline exceeded.');
        }
    }

    /**
     * @param resource $connection
     */
    public function readLine(mixed $connection, OperationDeadline $deadline): string
    {
        $this->assertExecutionAllowed($deadline);

        if ($this->streamWaiter === null) {
            return $this->readBlockingLine($connection, $deadline);
        }

        return $this->readCooperativeLine($connection, $deadline);
    }

    /**
     * @param resource $connection
     */
    public function write(mixed $connection, string $data, OperationDeadline $deadline): void
    {
        $dataLength = strlen($data);
        $bytesWritten = 0;

        while ($bytesWritten < $dataLength) {
            $this->assertExecutionAllowed($deadline);
            if ($this->streamWaiter !== null && !$this->streamWaiter->waitWritable($connection, $deadline)) {
                $this->assertExecutionAllowed($deadline);

                throw new RuntimeException('SMTP cooperative write wait was interrupted.');
            }

            $written = fwrite($connection, substr($data, $bytesWritten));
            if ($written === false) {
                throw new RuntimeException('Failed to write to SMTP server socket.');
            }
            if ($written === 0) {
                if ($this->streamWaiter !== null) {
                    continue;
                }

                throw new RuntimeException('Failed to write to SMTP server socket.');
            }

            $bytesWritten += $written;
        }
    }

    /**
     * @param resource $connection
     */
    private function readBlockingLine(mixed $connection, OperationDeadline $deadline): string
    {
        $line = fgets($connection, 1024);
        if ($line === false) {
            /** @var array<string, mixed> $metadata */
            $metadata = stream_get_meta_data($connection);
            if (($metadata['timed_out'] ?? false) === true) {
                throw new RuntimeException('SMTP server response timed out.');
            }

            $this->assertExecutionAllowed($deadline);

            throw new RuntimeException('Failed to read SMTP server response.');
        }

        $this->assertExecutionAllowed($deadline);

        return $line;
    }

    /**
     * @param resource $connection
     */
    private function readCooperativeLine(mixed $connection, OperationDeadline $deadline): string
    {
        $line = '';

        while (strlen($line) < 1023) {
            if (!$this->streamWaiter?->waitReadable($connection, $deadline)) {
                $this->assertExecutionAllowed($deadline);

                throw new RuntimeException('SMTP cooperative read wait was interrupted.');
            }

            $chunk = fgets($connection, max(2, 1024 - strlen($line)));
            $this->assertExecutionAllowed($deadline);
            if ($chunk === false) {
                if (feof($connection)) {
                    throw new RuntimeException('Failed to read SMTP server response.');
                }

                continue;
            }

            $line .= $chunk;
            if (str_ends_with($line, "\n") || feof($connection)) {
                return $line;
            }
        }

        throw new RuntimeException('SMTP response line exceeds 1023 bytes.');
    }
}
