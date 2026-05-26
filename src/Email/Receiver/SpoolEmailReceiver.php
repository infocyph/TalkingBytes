<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Receiver;

use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Event\EmailEventBus;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;
use RuntimeException;

final readonly class SpoolEmailReceiver implements EmailReceiver
{
    public function __construct(
        private SpoolConfig $config,
        private EmailParser $parser = new RawEmailParser(),
        private bool $deleteAfterRead = false,
        private ?string $moveAfterRead = null,
        private ?string $failedDirectory = null,
    ) {}

    public function peek(): ?ParsedEmail
    {
        return $this->readNext(consume: false);
    }

    public function receive(): ?ParsedEmail
    {
        return $this->receiveParsed();
    }

    /**
     * @return list<ParsedEmail>
     */
    public function receiveMany(?int $limit = null): array
    {
        $limit ??= $this->config->maxMessages;

        if ($limit < 1) {
            return [];
        }

        $received = [];

        while (count($received) < $limit) {
            $parsed = $this->receiveParsed();
            if ($parsed === null) {
                break;
            }

            $received[] = $parsed;
        }

        return $received;
    }

    public function receiveParsed(): ?ParsedEmail
    {
        return $this->readNext(consume: true);
    }

    private function beginProcessing(string $file, bool $consume): string
    {
        if (!$consume || $this->config->processingDirectory === null || $this->config->processingDirectory === '') {
            return $file;
        }

        return $this->moveFileToDirectory($file, $this->config->processingDirectory, ensureUnique: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetadata(string $originalPath, string $processingPath, bool $consume): array
    {
        return [
            'source' => 'spool',
            'path' => $originalPath,
            'original_path' => $originalPath,
            'processing_path' => $processingPath,
            'consumed_at' => $consume ? gmdate(DATE_ATOM) : null,
            'size_bytes' => filesize($processingPath) ?: 0,
        ];
    }

    private function deleteFile(string $file, bool $strict): void
    {
        if (!file_exists($file)) {
            return;
        }

        if (!unlink($file) && $strict) {
            throw new RuntimeException(sprintf('Unable to delete file "%s".', $file));
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }
    }

    private function finalizeRead(string $file): void
    {
        if ($this->moveAfterRead !== null && $this->moveAfterRead !== '') {
            $this->moveFileToDirectory($file, $this->moveAfterRead);

            return;
        }

        if ($this->deleteAfterRead) {
            $this->deleteFile($file, strict: true);
        }
    }

    private function firstSpoolFile(): ?string
    {
        $directory = rtrim($this->config->directory, '/\\');
        if (!is_dir($directory)) {
            return null;
        }

        $extension = ltrim($this->config->extension, '.');
        $files = glob(sprintf('%s/*.%s', $directory, $extension));
        if ($files === false || $files === []) {
            return null;
        }

        $now = time();
        $candidates = [];

        foreach ($files as $file) {
            $mtime = filemtime($file);
            if ($mtime === false) {
                continue;
            }

            $age = $now - $mtime;

            if ($this->config->olderThanSeconds !== null && $age < $this->config->olderThanSeconds) {
                continue;
            }

            if ($this->config->newerThanSeconds !== null && $age > $this->config->newerThanSeconds) {
                continue;
            }

            $candidates[] = $file;
        }

        if ($candidates === []) {
            return null;
        }

        sort($candidates);

        return $candidates[0];
    }

    private function markFailed(string $file, string $reason): void
    {
        if ($this->failedDirectory !== null && $this->failedDirectory !== '') {
            try {
                $target = $this->moveFileToDirectory($file, $this->failedDirectory);
                $errorPath = $target . '.error.txt';
                file_put_contents($errorPath, $reason);
            } catch (\Throwable) {
                // Best effort quarantine.
            }

            return;
        }

        if ($this->deleteAfterRead) {
            $this->deleteFile($file, strict: false);
        }
    }

    private function moveFileToDirectory(string $file, string $directory, bool $ensureUnique = false): string
    {
        $this->ensureDirectory($directory);

        $target = rtrim($directory, '/\\') . '/' . basename($file);
        if ($ensureUnique) {
            $target = $this->uniqueTarget($directory, basename($file));
        }

        if (!rename($file, $target)) {
            throw new RuntimeException(sprintf('Unable to move file "%s" to "%s".', $file, $target));
        }

        return $target;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function parseFile(string $raw, array $metadata): ParsedEmail
    {
        return $this->parser->parse($raw, $metadata);
    }

    private function readFile(string $file, bool $exclusiveLock): string|false
    {
        if (!$this->config->lockBeforeRead) {
            return file_get_contents($file);
        }

        $handle = fopen($file, 'rb');
        if (!is_resource($handle)) {
            return false;
        }

        $lockMode = $exclusiveLock ? LOCK_EX : LOCK_SH;
        if (!flock($handle, $lockMode)) {
            fclose($handle);

            return false;
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return is_string($contents) ? $contents : false;
    }

    private function readNext(bool $consume): ?ParsedEmail
    {
        $sourceFile = $this->firstSpoolFile();
        if ($sourceFile === null) {
            return null;
        }

        $processingFile = $sourceFile;
        $startedAt = microtime(true);
        EmailEventBus::dispatch('email.receive.start', [
            'source' => 'spool',
            'path' => $sourceFile,
            'consume' => $consume,
        ]);

        try {
            $processingFile = $this->beginProcessing($sourceFile, $consume);
            $raw = $this->readFile($processingFile, $consume);
            if ($raw === false) {
                $this->markFailed($processingFile, 'Unable to read spool file.');
                EmailEventBus::dispatch('email.receive.finish', [
                    'source' => 'spool',
                    'successful' => false,
                    'path' => $processingFile,
                    'error' => 'Unable to read spool file.',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);

                return null;
            }

            $parsed = $this->parseFile($raw, $this->buildMetadata($sourceFile, $processingFile, $consume));
        } catch (\Throwable $exception) {
            $this->markFailed($processingFile, $exception->getMessage());
            EmailEventBus::dispatch('email.parse.failed', [
                'source' => 'spool',
                'path' => $processingFile,
                'error' => $exception->getMessage(),
            ]);
            EmailEventBus::dispatch('email.receive.finish', [
                'source' => 'spool',
                'successful' => false,
                'path' => $processingFile,
                'error' => $exception->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return null;
        }

        if ($consume) {
            $this->finalizeRead($processingFile);
        }

        EmailEventBus::dispatch('email.receive.finish', [
            'source' => 'spool',
            'successful' => true,
            'path' => $processingFile,
            'subject' => $parsed->subject,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $parsed;
    }

    private function uniqueTarget(string $directory, string $basename): string
    {
        $target = rtrim($directory, '/\\') . '/' . $basename;
        if (!file_exists($target)) {
            return $target;
        }

        $pathInfo = pathinfo($basename);
        $name = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? ('.' . $pathInfo['extension']) : '';

        return sprintf('%s/%s-%s%s', rtrim($directory, '/\\'), $name, bin2hex(random_bytes(4)), $extension);
    }
}
