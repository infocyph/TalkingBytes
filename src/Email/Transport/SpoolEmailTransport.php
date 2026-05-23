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
            $spoolResult = $this->spool($message, $rawEmail->raw, $rawEmail->sizeBytes);
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
                'spool_path' => $spoolResult['path'],
                'size_bytes' => $rawEmail->sizeBytes,
                'metadata_write_failed' => $spoolResult['metadataError'] !== null,
                'metadata_write_error' => $spoolResult['metadataError'],
            ],
        );

        return CommunicationResult::success(response: $result, metadata: $result->metadata);
    }

    private function ensureWritableDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create spool directory: %s', $directory));
        }

        if (!is_writable($directory)) {
            throw new RuntimeException(sprintf('Spool directory is not writable: %s', $directory));
        }
    }

    private function extractMessageId(string $headers): ?string
    {
        if (preg_match('/^Message-ID:\s*(.+)$/mi', $headers, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function finalizeEmailFile(string $tempPath, string $finalPath): void
    {
        if (rename($tempPath, $finalPath)) {
            return;
        }

        $this->removeFileIfExists($tempPath);

        throw new RuntimeException(sprintf('Unable to finalize spooled email: %s', $finalPath));
    }

    private function removeFileIfExists(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @return array{path:string,metadataError:?string}
     */
    private function spool(EmailMessage $message, string $rawEmail, int $sizeBytes): array
    {
        $directory = rtrim($this->config->directory, '/\\');
        $this->ensureWritableDirectory($directory);

        $id = sprintf('%s_%s', new DateTimeImmutable()->format('Ymd_His'), bin2hex(random_bytes(6)));
        $finalPath = sprintf('%s/%s.eml', $directory, $id);
        $tempPath = sprintf('%s/.%s.tmp', $directory, $id);

        $this->writeTempFile($tempPath, $rawEmail);
        $this->finalizeEmailFile($tempPath, $finalPath);

        $metadataError = null;
        if ($this->config->writeMetadata) {
            try {
                $this->writeMetadata($directory, $id, $message, $sizeBytes);
            } catch (RuntimeException $exception) {
                $metadataError = $exception->getMessage();
            }
        }

        return ['path' => $finalPath, 'metadataError' => $metadataError];
    }

    private function writeMetadata(string $directory, string $id, EmailMessage $message, int $sizeBytes): void
    {
        $metadataPath = sprintf('%s/%s.json', $directory, $id);
        $metadata = [
            'created_at' => new DateTimeImmutable()->format(DATE_ATOM),
            'subject' => $message->headersData()->subject,
            'recipients' => array_map(static fn($address): string => $address->email, $message->envelope()->recipients()),
            'size_bytes' => $sizeBytes,
            'metadata' => $message->metadata(),
        ];

        $encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException(sprintf('Unable to encode spool metadata: %s', $metadataPath));
        }

        if (file_put_contents($metadataPath, $encoded, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write spool metadata file: %s', $metadataPath));
        }
    }

    private function writeTempFile(string $tempPath, string $rawEmail): void
    {
        if (file_put_contents($tempPath, $rawEmail, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write spool temp file: %s', $tempPath));
        }
    }
}
