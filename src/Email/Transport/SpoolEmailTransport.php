<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use DateTimeImmutable;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Result\EmailSendResult;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;
use RuntimeException;

final readonly class SpoolEmailTransport implements EmailTransport
{
    public function __construct(
        private SpoolConfig $config,
        private RawEmailBuilder $rawEmailBuilder = new RawEmailBuilder(),
        private EmailHeaderBuilder $headerBuilder = new EmailHeaderBuilder(),
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $message->assertReadyToSend();

        $rawEmail = $this->rawEmailBuilder->build($message, includeSubject: true);
        $messageId = $this->extractMessageId($rawEmail->headers) ?? $this->headerBuilder->resolveMessageId($message);
        $recipients = array_map(static fn($address): string => $address->email, $message->envelope()->recipients());

        try {
            $spooledPath = $this->spool($message, $rawEmail->raw, $rawEmail->sizeBytes);
        } catch (RuntimeException $exception) {
            $result = new EmailSendResult(
                'spool-email',
                $messageId,
                [],
                array_fill_keys($recipients, $exception->getMessage()),
                ['transport' => 'spool-email'],
            );

            return CommunicationResult::failure(
                $exception->getMessage(),
                response: $result,
                metadata: $result->metadata,
            );
        }

        $result = new EmailSendResult(
            'spool-email',
            $messageId,
            $recipients,
            [],
            [
                'transport' => 'spool-email',
                'spool_path' => $spooledPath,
                'size_bytes' => $rawEmail->sizeBytes,
            ],
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

    private function spool(EmailMessage $message, string $rawEmail, int $sizeBytes): string
    {
        $directory = rtrim($this->config->directory, '/\\');

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create spool directory: %s', $directory));
        }

        if (!is_writable($directory)) {
            throw new RuntimeException(sprintf('Spool directory is not writable: %s', $directory));
        }

        $id = sprintf('%s_%s', new DateTimeImmutable()->format('Ymd_His'), bin2hex(random_bytes(6)));
        $finalPath = sprintf('%s/%s.eml', $directory, $id);
        $tempPath = sprintf('%s/.%s.tmp', $directory, $id);

        if (file_put_contents($tempPath, $rawEmail, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write spool temp file: %s', $tempPath));
        }

        if (!rename($tempPath, $finalPath)) {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }

            throw new RuntimeException(sprintf('Unable to finalize spooled email: %s', $finalPath));
        }

        if ($this->config->writeMetadata) {
            $metadataPath = sprintf('%s/%s.json', $directory, $id);
            $metadata = [
                'created_at' => new DateTimeImmutable()->format(DATE_ATOM),
                'subject' => $message->headersData()->subject,
                'recipients' => array_map(static fn($address): string => $address->email, $message->envelope()->recipients()),
                'size_bytes' => $sizeBytes,
                'metadata' => $message->metadata(),
            ];

            file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $finalPath;
    }
}
