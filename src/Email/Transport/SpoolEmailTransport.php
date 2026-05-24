<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use DateTimeImmutable;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;
use RuntimeException;

final class SpoolEmailTransport extends AbstractRawEmailTransport implements EmailTransport
{
    public function __construct(
        private readonly SpoolConfig $config,
        ?RawEmailBuilder $rawEmailBuilder = null,
        ?EmailHeaderBuilder $headerBuilder = null,
    ) {
        parent::__construct(
            $rawEmailBuilder ?? new RawEmailBuilder(),
            $headerBuilder ?? new EmailHeaderBuilder(),
        );
    }

    public function send(EmailMessage $message): CommunicationResult
    {
        $message->assertReadyToSend();

        $messageId = $this->headerBuilder->resolveMessageId($message);
        $recipients = array_map(static fn($address): string => $address->email, $message->envelope()->recipients());

        try {
            $spoolResult = $this->spool($message);
        } catch (RuntimeException $exception) {
            return EmailTransportResultFactory::failure(
                'spool-email',
                $messageId,
                $exception->getMessage(),
                $recipients,
            );
        }

        return EmailTransportResultFactory::success('spool-email', $messageId, $recipients, [
            'spool_path' => $spoolResult['path'],
            'size_bytes' => $spoolResult['sizeBytes'],
            'metadata_write_failed' => $spoolResult['metadataError'] !== null,
            'metadata_write_error' => $spoolResult['metadataError'],
        ]);
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
     * @return array{path:string,metadataError:?string,sizeBytes:int}
     */
    private function spool(EmailMessage $message): array
    {
        $directory = rtrim($this->config->directory, '/\\');
        $this->ensureWritableDirectory($directory);

        $id = sprintf('%s_%s', new DateTimeImmutable()->format('Ymd_His'), bin2hex(random_bytes(6)));
        $finalPath = sprintf('%s/%s.eml', $directory, $id);
        $tempPath = sprintf('%s/.%s.tmp', $directory, $id);

        try {
            $sizeBytes = $this->writeTempFile($tempPath, $message);
        } catch (RuntimeException $exception) {
            $this->removeFileIfExists($tempPath);

            throw $exception;
        }

        $this->finalizeEmailFile($tempPath, $finalPath);

        $metadataError = null;
        if ($this->config->writeMetadata) {
            try {
                $this->writeMetadata($directory, $id, $message, $sizeBytes);
            } catch (RuntimeException $exception) {
                $metadataError = $exception->getMessage();
            }
        }

        return ['path' => $finalPath, 'metadataError' => $metadataError, 'sizeBytes' => $sizeBytes];
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

    /**
     * @param resource $stream
     */
    private function writeStreamChunk($stream, string $chunk): void
    {
        $length = strlen($chunk);
        $written = 0;

        while ($written < $length) {
            $current = fwrite($stream, substr($chunk, $written));
            if ($current === false || $current === 0) {
                throw new RuntimeException('Unable to write spool temp file chunk.');
            }

            $written += $current;
        }
    }

    private function writeTempFile(string $tempPath, EmailMessage $message): int
    {
        $stream = fopen($tempPath, 'w+b');
        if (!is_resource($stream)) {
            throw new RuntimeException(sprintf('Unable to open spool temp file for writing: %s', $tempPath));
        }

        if (!flock($stream, LOCK_EX)) {
            fclose($stream);

            throw new RuntimeException(sprintf('Unable to lock spool temp file: %s', $tempPath));
        }

        try {
            return $this->rawEmailBuilder->buildToStream(
                $message,
                function (string $chunk) use ($stream): void {
                    $this->writeStreamChunk($stream, $chunk);
                },
                includeSubject: true,
                maxBytes: $this->config->maxMessageBytes,
            );
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }
}
