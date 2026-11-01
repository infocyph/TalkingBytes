<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Receiver;

use FilesystemIterator;
use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;
use RuntimeException;
use SplFileInfo;

final readonly class SpoolEmailReceiver implements EmailReceiver
{
    private Clock $clock;

    private EventDispatcher $events;

    public function __construct(
        private SpoolConfig $config,
        private EmailParser $parser = new RawEmailParser(),
        private bool $deleteAfterRead = false,
        private ?string $moveAfterRead = null,
        private ?string $failedDirectory = null,
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        private ?CancellationSignal $cancellation = null,
        private ?OperationDeadline $operationDeadline = null,
    ) {
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
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

    private function assertExecutionAllowed(): void
    {
        if ($this->cancellation?->isRequested() === true) {
            throw new RuntimeException('Email receive operation cancelled.');
        }

        if ($this->operationDeadline?->expired() === true) {
            throw new RuntimeException('Email receive operation deadline exceeded.');
        }
    }

    private function beginProcessing(string $file, bool $consume): ?string
    {
        if (!$consume) {
            return $file;
        }

        if ($this->config->processingDirectory !== null && $this->config->processingDirectory !== '') {
            try {
                $this->ensureDirectory($this->config->processingDirectory);
                if (!$this->isSameFilesystem($file, $this->config->processingDirectory)) {
                    return null;
                }

                return $this->moveFileToDirectory($file, $this->config->processingDirectory, ensureUnique: true);
            } catch (RuntimeException) {
                return null;
            }
        }

        $claim = dirname($file) . '/.' . basename($file) . '.' . bin2hex(random_bytes(8)) . '.processing';
        if (!$this->tryRename($file, $claim)) {
            return null;
        }

        return $claim;
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
            'consumed_at' => $consume ? gmdate(DATE_ATOM, (int) floor($this->clock->timestamp())) : null,
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

    private function finalizeRead(string $file, string $sourceFile): void
    {
        if ($this->moveAfterRead !== null && $this->moveAfterRead !== '') {
            $this->moveFileToDirectory(
                $file,
                $this->moveAfterRead,
                ensureUnique: true,
                targetBasename: basename($sourceFile),
            );

            return;
        }

        if ($this->deleteAfterRead) {
            $this->deleteFile($file, strict: true);

            return;
        }

        if ($file !== $sourceFile && !$this->tryRename($file, $sourceFile)) {
            throw new RuntimeException(sprintf('Unable to restore claimed spool file "%s".', $sourceFile));
        }
    }

    private function firstSpoolFile(): ?string
    {
        $directory = rtrim($this->config->directory, '/\\\\');
        if (!is_dir($directory)) {
            return null;
        }

        $extension = ltrim($this->config->extension, '.');
        $now = (int) floor($this->clock->timestamp());
        $candidate = null;
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            $file = $this->eligibleSpoolFile($entry, $extension, $now);
            if ($file !== null && ($candidate === null || strcmp($file, $candidate) < 0)) {
                $candidate = $file;
            }
        }

        return $candidate;
    }

    private function eligibleSpoolFile(SplFileInfo $entry, string $extension, int $now): ?string
    {
        if ($entry->isLink() || !$entry->isFile() || strcasecmp($entry->getExtension(), $extension) !== 0) {
            return null;
        }

        $file = $entry->getPathname();
        $mtime = filemtime($file);
        if ($mtime === false) {
            return null;
        }

        $age = $now - $mtime;
        if ($this->config->olderThanSeconds !== null && $age < $this->config->olderThanSeconds) {
            return null;
        }
        if ($this->config->newerThanSeconds !== null && $age > $this->config->newerThanSeconds) {
            return null;
        }

        return $file;
    }

    private function isSameFilesystem(string $file, string $directory): bool
    {
        $source = stat($file);
        $target = stat($directory);
        if (!is_array($source) || !is_array($target)) {
            return false;
        }

        return $source['dev'] === $target['dev'];
    }

    private function markFailed(string $file, string $reason, string $sourceFile): void
    {
        if ($this->failedDirectory !== null && $this->failedDirectory !== '') {
            try {
                $target = $this->moveFileToDirectory(
                    $file,
                    $this->failedDirectory,
                    ensureUnique: true,
                    targetBasename: basename($sourceFile),
                );
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

            return;
        }

        if ($file !== $sourceFile && file_exists($file) && !file_exists($sourceFile)) {
            $this->tryRename($file, $sourceFile);
        }
    }

    private function moveFileToDirectory(
        string $file,
        string $directory,
        bool $ensureUnique = false,
        ?string $targetBasename = null,
    ): string {
        $this->ensureDirectory($directory);

        $basename = $targetBasename ?? basename($file);
        $target = rtrim($directory, '/\\') . '/' . $basename;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $ensureUnique ? $this->uniqueTarget($directory, $basename) : $target;
            $published = $this->tryLink($file, $candidate);
            if (!$published && !$this->isSameFilesystem($file, $directory) && !file_exists($candidate)) {
                $published = $this->publishAcrossFilesystems($file, $directory, $candidate);
            }
            if ($published) {
                $this->removePublishedSource($file, $candidate);

                return $candidate;
            }
            if (!$ensureUnique || !file_exists($candidate)) {
                break;
            }
        }

        throw new RuntimeException(sprintf('Unable to move file "%s" without overwriting a destination.', $file));
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

        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $handle = fopen($file, 'rb');
        } finally {
            restore_error_handler();
        }

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
        $this->assertExecutionAllowed();
        $sourceFile = $this->firstSpoolFile();
        if ($sourceFile === null) {
            return null;
        }

        $processingFile = $sourceFile;
        $ownsClaim = false;
        $startedAt = $this->clock->monotonic();
        $this->events->dispatch('email.receive.start', [
            'source' => 'spool',
            'consume' => $consume,
        ]);

        try {
            $claimedFile = $this->beginProcessing($sourceFile, $consume);
            if ($claimedFile === null) {
                $this->events->dispatch('email.receive.finish', [
                    'source' => 'spool',
                    'successful' => false,
                    'failure_category' => 'claim_failure',
                    'duration_ms' => (int) round(($this->clock->monotonic() - $startedAt) * 1000),
                ]);

                return null;
            }

            $processingFile = $claimedFile;
            $ownsClaim = $consume && $processingFile !== $sourceFile;
            $raw = $this->readFile($processingFile, $consume);
            if ($raw === false) {
                if ($ownsClaim) {
                    $this->markFailed($processingFile, 'Unable to read spool file.', $sourceFile);
                }
                $this->events->dispatch('email.receive.finish', [
                    'source' => 'spool',
                    'successful' => false,
                    'failure_category' => 'read_failure',
                    'duration_ms' => (int) round(($this->clock->monotonic() - $startedAt) * 1000),
                ]);

                return null;
            }

            $parsed = $this->parseFile($raw, $this->buildMetadata($sourceFile, $processingFile, $consume));
        } catch (\Throwable $exception) {
            if ($ownsClaim) {
                $this->markFailed($processingFile, $exception->getMessage(), $sourceFile);
            }
            $this->events->dispatch('email.parse.failed', [
                'source' => 'spool',
                'failure_category' => 'parse_failure',
                'exception_class' => $exception::class,
            ]);
            $this->events->dispatch('email.receive.finish', [
                'source' => 'spool',
                'successful' => false,
                'failure_category' => 'parse_failure',
                'exception_class' => $exception::class,
                'duration_ms' => (int) round(($this->clock->monotonic() - $startedAt) * 1000),
            ]);

            return null;
        }

        if ($consume) {
            $this->finalizeRead($processingFile, $sourceFile);
        }

        $this->events->dispatch('email.receive.finish', [
            'source' => 'spool',
            'successful' => true,
            'duration_ms' => (int) round(($this->clock->monotonic() - $startedAt) * 1000),
        ]);

        return $parsed;
    }

    private function publishAcrossFilesystems(string $source, string $directory, string $target): bool
    {
        $staging = tempnam($directory, '.tb-spool-');
        if ($staging === false) {
            throw new RuntimeException('Unable to allocate spool destination staging file.');
        }

        try {
            if (!$this->tryCopy($source, $staging) || !chmod($staging, 0600)) {
                throw new RuntimeException('Unable to safely stage spool message on destination filesystem.');
            }

            return $this->tryLink($staging, $target);
        } finally {
            $this->deleteFile($staging, strict: false);
        }
    }

    private function removePublishedSource(string $source, string $destination): void
    {
        if (unlink($source)) {
            return;
        }

        $this->deleteFile($destination, strict: false);
        throw new RuntimeException(sprintf('Unable to remove spool source after publication: %s', $source));
    }

    private function tryCopy(string $source, string $target): bool
    {
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            return copy($source, $target);
        } finally {
            restore_error_handler();
        }
    }

    private function tryLink(string $source, string $target): bool
    {
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            return link($source, $target);
        } finally {
            restore_error_handler();
        }
    }

    private function tryRename(string $source, string $target): bool
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return rename($source, $target);
        } finally {
            restore_error_handler();
        }
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
