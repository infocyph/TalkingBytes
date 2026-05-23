<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Receiver;

use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;
use Infocyph\TalkingBytes\Email\ValueObject\ReceivedEmail;
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

    public function receive(): ?ReceivedEmail
    {
        $parsed = $this->receiveParsed();
        if ($parsed === null) {
            return null;
        }

        return ReceivedEmail::fromParsedEmail($parsed);
    }

    /**
     * @return list<ParsedEmail>
     */
    public function receiveMany(int $limit = 20): array
    {
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

    private function deleteFile(string $file, bool $strict): void
    {
        if (!file_exists($file)) {
            return;
        }

        if (!unlink($file) && $strict) {
            throw new RuntimeException(sprintf('Unable to delete file "%s".', $file));
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

        $files = glob($directory . '/*.eml');
        if ($files === false || $files === []) {
            return null;
        }

        sort($files);

        return $files[0];
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

    private function moveFileToDirectory(string $file, string $directory): string
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }

        $target = rtrim($directory, '/\\') . '/' . basename($file);
        if (!rename($file, $target)) {
            throw new RuntimeException(sprintf('Unable to move file "%s" to "%s".', $file, $target));
        }

        return $target;
    }

    private function readNext(bool $consume): ?ParsedEmail
    {
        $file = $this->firstSpoolFile();
        if ($file === null) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            $this->markFailed($file, 'Unable to read spool file.');

            return null;
        }

        try {
            $parsed = $this->parser->parse($raw, [
                'source' => 'spool',
                'path' => $file,
            ]);
        } catch (\Throwable $exception) {
            $this->markFailed($file, $exception->getMessage());

            return null;
        }

        if ($consume) {
            $this->finalizeRead($file);
        }

        return $parsed;
    }
}
