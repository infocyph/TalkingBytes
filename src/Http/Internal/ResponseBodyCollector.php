<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

use Infocyph\TalkingBytes\Http\HttpRequest;
use InvalidArgumentException;

final class ResponseBodyCollector
{
    private string $body = '';

    private ?string $error = null;

    private int $receivedBytes = 0;

    /** @var resource|null */
    private mixed $stream = null;

    private ?string $targetPath = null;

    private ?string $tempPath = null;

    public function __construct(private readonly HttpRequest $request)
    {
        $path = $request->options->streamDownloadPath;
        if ($path === null) {
            return;
        }

        $this->prepareStreamDownload($path);
    }

    public function collect(string $chunk): int
    {
        if ($this->error !== null) {
            return 0;
        }

        $length = strlen($chunk);
        $this->receivedBytes += $length;

        if (
            $this->request->options->maxResponseBytes !== null
            && $this->request->options->streamDownloadPath === null
            && $this->receivedBytes > $this->request->options->maxResponseBytes
        ) {
            $this->error = sprintf('HTTP response exceeded max allowed bytes (%d).', $this->request->options->maxResponseBytes);

            return 0;
        }

        if (
            $this->request->options->maxDownloadBytes !== null
            && $this->request->options->streamDownloadPath !== null
            && $this->receivedBytes > $this->request->options->maxDownloadBytes
        ) {
            $this->error = sprintf('HTTP download exceeded max allowed bytes (%d).', $this->request->options->maxDownloadBytes);

            return 0;
        }

        if ($this->stream !== null) {
            $written = fwrite($this->stream, $chunk);

            if ($written === false || $written !== $length) {
                $this->error = sprintf('Failed to write streamed download file: %s', (string) $this->targetPath);

                return 0;
            }

            return $length;
        }

        $this->body .= $chunk;

        return $length;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function finalize(): ?string
    {
        if ($this->stream === null) {
            return $this->error;
        }

        fclose($this->stream);
        $this->stream = null;

        if ($this->error !== null) {
            $this->cleanupTempFile();

            return $this->error;
        }

        if ($this->targetPath === null || $this->tempPath === null) {
            $this->error = 'Stream download destination was not initialized.';

            return $this->error;
        }

        if (!rename($this->tempPath, $this->targetPath)) {
            $this->error = sprintf('Failed to finalize streamed download file: %s', $this->targetPath);
            $this->cleanupTempFile();

            return $this->error;
        }

        $this->tempPath = null;

        return null;
    }

    public function responseBody(): string
    {
        return $this->body;
    }

    private function cleanupTempFile(): void
    {
        if ($this->tempPath !== null && is_file($this->tempPath)) {
            unlink($this->tempPath);
        }

        $this->tempPath = null;
    }

    private function prepareStreamDownload(string $path): void
    {
        if (is_dir($path)) {
            throw new InvalidArgumentException(sprintf('Stream download path points to a directory: %s', $path));
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new InvalidArgumentException(sprintf('Stream download directory does not exist: %s', $directory));
        }

        if (!is_writable($directory)) {
            throw new InvalidArgumentException(sprintf('Stream download directory is not writable: %s', $directory));
        }

        $tempPath = tempnam($directory, 'tb-http-download-');
        if ($tempPath === false) {
            throw new InvalidArgumentException(sprintf('Unable to allocate temporary stream download file in directory: %s', $directory));
        }

        $stream = fopen($tempPath, 'wb');
        if ($stream === false) {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }

            throw new InvalidArgumentException(sprintf('Unable to open stream download temp file for writing: %s', $tempPath));
        }

        $this->targetPath = $path;
        $this->tempPath = $tempPath;
        $this->stream = $stream;
    }
}
