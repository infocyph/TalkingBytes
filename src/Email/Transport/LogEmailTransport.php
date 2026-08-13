<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use DateTimeImmutable;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;
use RuntimeException;

final class LogEmailTransport extends AbstractRawEmailTransport implements EmailTransport
{
    public function __construct(private readonly LogEmailConfig $config, EmailHeaderBuilder $headerBuilder = new EmailHeaderBuilder(), RawEmailBuilder $rawEmailBuilder = new RawEmailBuilder())
    {
        parent::__construct($rawEmailBuilder, $headerBuilder);
    }

    public function send(EmailMessage $message): CommunicationResult
    {
        $message = $message->prepare();
        $messageId = $this->headerBuilder->resolveMessageId($message);
        $path = '';
        $sizeBytes = 0;

        $recipients = array_map(static fn($address): string => $address->email, $message->envelope()->recipients());

        try {
            $path = $this->resolvePath();
            $sizeBytes = $this->writeLog($path, $message);
        } catch (RuntimeException $exception) {
            return EmailTransportResultFactory::failure(
                'log-email',
                $messageId,
                $exception->getMessage(),
                $recipients,
                ['log_path' => $path],
            );
        }

        return EmailTransportResultFactory::success('log-email', $messageId, $recipients, [
            'log_path' => $path,
            'size_bytes' => $sizeBytes,
        ]);
    }

    private function resolvePath(): string
    {
        if (file_exists($this->config->directory) && !is_dir($this->config->directory)) {
            throw new RuntimeException(sprintf('Log path is not a directory: %s', $this->config->directory));
        }

        if (!is_dir($this->config->directory) && !mkdir($this->config->directory, 0700, true) && !is_dir($this->config->directory)) {
            throw new RuntimeException(sprintf('Unable to create log directory: %s', $this->config->directory));
        }

        if (!is_writable($this->config->directory)) {
            throw new RuntimeException(sprintf('Log directory is not writable: %s', $this->config->directory));
        }

        $suffix = $this->config->dailyFiles
            ? new DateTimeImmutable()->format('Y-m-d')
            : 'emails';

        return rtrim($this->config->directory, '/\\') . '/' . $this->config->filenamePrefix . '-' . $suffix . '.log';
    }

    /** @param resource $stream */
    private function writeChunk($stream, string $chunk): void
    {
        $remaining = $chunk;
        while ($remaining !== '') {
            $written = fwrite($stream, $remaining);
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('Unable to stream email log payload.');
            }
            $remaining = substr($remaining, $written);
        }
    }

    private function writeLog(string $path, EmailMessage $message): int
    {
        $stream = fopen($path, 'ab');
        if (!is_resource($stream)) {
            throw new RuntimeException(sprintf('Unable to open email log file: %s', $path));
        }
        chmod($path, 0600);

        try {
            if (!flock($stream, LOCK_EX)) {
                throw new RuntimeException(sprintf('Unable to lock email log file: %s', $path));
            }
            $this->writeChunk($stream, sprintf("----- %s -----\n", new DateTimeImmutable()->format(DATE_ATOM)));
            $sizeBytes = $this->rawEmailBuilder->buildToStream(
                $message,
                function (string $chunk) use ($stream): void {
                    $this->writeChunk($stream, $chunk);
                },
                includeSubject: true,
                maxBytes: $this->config->maxMessageBytes,
            );
            $this->writeChunk($stream, "\n\n");
            fflush($stream);
            flock($stream, LOCK_UN);

            return $sizeBytes;
        } finally {
            fclose($stream);
        }
    }
}
