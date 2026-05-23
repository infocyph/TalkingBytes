<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use DateTimeImmutable;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Result\EmailSendResult;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;

final readonly class LogEmailTransport implements EmailTransport
{
    public function __construct(
        private LogEmailConfig $config,
        private RawEmailBuilder $rawEmailBuilder = new RawEmailBuilder(),
        private EmailHeaderBuilder $headerBuilder = new EmailHeaderBuilder(),
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $message->assertReadyToSend();

        $rawEmail = $this->rawEmailBuilder->build($message, includeSubject: true);
        $messageId = $this->extractMessageId($rawEmail->headers) ?? $this->headerBuilder->resolveMessageId($message);
        $path = $this->resolvePath();
        $written = $this->writeLog($path, $rawEmail->raw);

        $recipients = array_map(static fn($address): string => $address->email, $message->envelope()->recipients());

        if (!$written) {
            $result = new EmailSendResult(
                'log-email',
                $messageId,
                [],
                array_fill_keys($recipients, sprintf('Unable to write email log file: %s', $path)),
                ['transport' => 'log-email', 'log_path' => $path],
            );

            return CommunicationResult::failure(
                sprintf('Unable to write email log file: %s', $path),
                response: $result,
                metadata: $result->metadata,
            );
        }

        $result = new EmailSendResult(
            'log-email',
            $messageId,
            $recipients,
            [],
            ['transport' => 'log-email', 'log_path' => $path, 'size_bytes' => $rawEmail->sizeBytes],
        );

        return CommunicationResult::success(response: $result, metadata: $result->metadata);
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
        if (!is_dir($this->config->directory)) {
            mkdir($this->config->directory, 0775, true);
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
