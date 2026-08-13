<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Receiver;

use FilesystemIterator;
use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;
use RuntimeException;
use SplFileInfo;

final readonly class SpoolEmailReceiver implements EmailReceiver
{
    public function __construct(
        private SpoolConfig $config,
        private EmailParser $parser = new RawEmailParser(),
        private bool $deleteAfterRead = false,
        private ?string $moveAfterRead = null,
        private ?string $failedDirectory = null,
    ) {
        $directories = array_filter([
            $this->config->directory,
            $this->config->processingDirectory,
            $this->moveAfterRead,
            $this->failedDirectory,
        ], is_string(...));
        $normalized = array_map(
            static fn(string $path): string => rtrim(str_replace('\\', '/', $path), '/'),
            $directories,
        );
        if (count($normalized) !== count(array_unique($normalized))) {
            throw new \InvalidArgumentException('Spool source, processing, success, and failure directories must be distinct.');
        }
    }

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
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
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
        $now = time();
        $candidate = null;
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            if (!$entry->isFile() || strtolower($entry->getExtension()) !== strtolower($extension)) {
                continue;
            }
            $file = $entry->getPathname();
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

            if ($candidate === null || strcmp($file, $candidate) < 0) {
                $candidate = $file;
            }
        }

        return $candidate;
    }

    private function markFailed(string $file, string $reason): void
    {
        if ($this->failedDirectory !== null && $this->failedDirectory !== '') {
            try {
                $target = $this->moveFileToDirectory($file, $this->failedDirectory);
                $errorPath = $target . '.error.txt';
                $safeReason = preg_replace('/[\x00-\x1F\x7F]/', ' ', $reason) ?? 'Spool processing failed.';
                file_put_contents($errorPath, substr($safeReason, 0, 4096), LOCK_EX);
                chmod($errorPath, 0600);
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
        $maxBytes = $this->config->maxMessageBytes ?? 10_485_760;
        $size = filesize($file);
        if (!is_int($size) || $size > $maxBytes) {
            throw new RuntimeException(sprintf('Spool email exceeds configured size limit (%d bytes).', $maxBytes));
        }

        $handle = fopen($file, 'rb');
        if (!is_resource($handle)) {
            return false;
        }

        $lockMode = $exclusiveLock ? LOCK_EX : LOCK_SH;
        if ($this->config->lockBeforeRead && !flock($handle, $lockMode)) {
            fclose($handle);

            return false;
        }

        $contents = stream_get_contents($handle, $maxBytes + 1);
        if ($this->config->lockBeforeRead) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return is_string($contents) && strlen($contents) <= $maxBytes ? $contents : false;
    }

    private function readNext(bool $consume): ?ParsedEmail
    {
        $sourceFile = $this->firstSpoolFile();
        if ($sourceFile === null) {
            return null;
        }

        $processingFile = $sourceFile;
        $startedAt = microtime(true);
        CommunicationEventBus::dispatch('email.receive.start', [
            'source' => 'spool',
            'path' => $sourceFile,
            'consume' => $consume,
        ]);

        try {
            $processingFile = $this->beginProcessing($sourceFile, $consume);
            $raw = $this->readFile($processingFile, $consume);
            if ($raw === false) {
                $this->markFailed($processingFile, 'Unable to read spool file.');
                CommunicationEventBus::dispatch('email.receive.finish', [
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
            CommunicationEventBus::dispatch('email.parse.failed', [
                'source' => 'spool',
                'path' => $processingFile,
                'error' => $exception->getMessage(),
            ]);
            CommunicationEventBus::dispatch('email.receive.finish', [
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

        CommunicationEventBus::dispatch('email.receive.finish', [
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
