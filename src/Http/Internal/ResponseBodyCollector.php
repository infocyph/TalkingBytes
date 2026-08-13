<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

use Infocyph\TalkingBytes\Http\HttpRequest;
use InvalidArgumentException;

final class ResponseBodyCollector
{
    private string $body = '';

    private ?string $error = null;

    private bool $finalized = false;

    private int $receivedBytes = 0;

    /** @var resource|null */
    private mixed $stream;

    private ?string $targetPath;

    private ?string $tempPath;

    public function __construct(
        private readonly HttpRequest $request,
        private readonly ResponseHeaderCollector $headers = new ResponseHeaderCollector(),
    ) {
        $this->stream = null;
        $this->targetPath = null;
        $this->tempPath = null;
    }

    public function collect(string $chunk): int
    {
        if ($this->finalized) {
            return 0;
        }

        if ($this->error !== null) {
            return 0;
        }

        $length = strlen($chunk);
        $this->receivedBytes += $length;
        $redirectResponse = $this->isRedirectResponse();

        if ($this->stream === null && $this->request->options->streamDownloadPath !== null && !$redirectResponse) {
            $this->prepareStreamDownload($this->request->options->streamDownloadPath);
        }

        if (!$this->withinConfiguredLimit($redirectResponse)) {
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
        if ($this->finalized) {
            return $this->error;
        }

        $this->finalized = true;
        $redirectResponse = $this->isRedirectResponse();
        if ($this->stream === null
            && $this->request->options->streamDownloadPath !== null
            && !$redirectResponse
            && $this->error === null
        ) {
            $this->prepareStreamDownload($this->request->options->streamDownloadPath);
        }

        if ($this->stream === null) {
            return $this->error;
        }

        if (!fflush($this->stream)) {
            $this->error = sprintf('Failed to flush streamed download file: %s', (string) $this->targetPath);
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

    private function isRedirectResponse(): bool
    {
        $statusCode = $this->headers->statusCode();

        return $statusCode !== null && $statusCode >= 300 && $statusCode < 400;
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

        chmod($tempPath, 0600);

        $this->targetPath = $path;
        $this->tempPath = $tempPath;
        $this->stream = $stream;
    }

    private function withinConfiguredLimit(bool $redirectResponse): bool
    {
        $maxResponseBytes = $this->request->options->maxResponseBytes;
        if ($maxResponseBytes !== null
            && ($this->request->options->streamDownloadPath === null || $redirectResponse)
            && $this->receivedBytes > $maxResponseBytes
        ) {
            $this->error = sprintf('HTTP response exceeded max allowed bytes (%d).', $maxResponseBytes);

            return false;
        }

        $maxDownloadBytes = $this->request->options->maxDownloadBytes;
        if ($maxDownloadBytes !== null && $this->stream !== null && $this->receivedBytes > $maxDownloadBytes) {
            $this->error = sprintf('HTTP download exceeded max allowed bytes (%d).', $maxDownloadBytes);

            return false;
        }

        return true;
    }
}
