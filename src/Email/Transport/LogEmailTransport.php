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
        $message->assertReadyToSend();

        $rawEmail = $this->rawEmailBuilder->build($message, includeSubject: true);
        if (
            $this->config->maxMessageBytes !== null
            && $rawEmail->sizeBytes > $this->config->maxMessageBytes
        ) {
            return CommunicationResult::failure(sprintf(
                'Email size %d bytes exceeds configured log max message size %d bytes.',
                $rawEmail->sizeBytes,
                $this->config->maxMessageBytes,
            ));
        }

        $messageId = $this->extractMessageId($rawEmail->headers) ?? $this->headerBuilder->resolveMessageId($message);
        $path = '';
        $written = false;

        $recipients = array_map(static fn($address): string => $address->email, $message->envelope()->recipients());

        try {
            $path = $this->resolvePath();
            $written = $this->writeLog($path, $rawEmail->raw);
        } catch (RuntimeException $exception) {
            return EmailTransportResultFactory::failure(
                'log-email',
                $messageId,
                $exception->getMessage(),
                $recipients,
                ['log_path' => $path],
            );
        }

        if (!$written) {
            return EmailTransportResultFactory::failure(
                'log-email',
                $messageId,
                sprintf('Unable to write email log file: %s', $path),
                $recipients,
                ['log_path' => $path],
            );
        }

        return EmailTransportResultFactory::success('log-email', $messageId, $recipients, [
            'log_path' => $path,
            'size_bytes' => $rawEmail->sizeBytes,
        ]);
    }

    private function extractMessageId(string $headers): ?string
    {
        if (preg_match('/^Message-ID:\s*(.+)$/mi', $headers, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function resolvePath(): string
    {
        if (file_exists($this->config->directory) && !is_dir($this->config->directory)) {
            throw new RuntimeException(sprintf('Log path is not a directory: %s', $this->config->directory));
        }

        if (!is_dir($this->config->directory) && !mkdir($this->config->directory, 0775, true) && !is_dir($this->config->directory)) {
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

    private function writeLog(string $path, string $rawEmail): bool
    {
        $payload = sprintf("----- %s -----\n%s\n\n", new DateTimeImmutable()->format(DATE_ATOM), $rawEmail);

        return file_put_contents($path, $payload, FILE_APPEND | LOCK_EX) !== false;
    }
}
