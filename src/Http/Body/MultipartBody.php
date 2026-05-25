<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Body;

use CURLFile;
use InvalidArgumentException;

final readonly class MultipartBody implements HttpBody
{
    /**
     * @param array<string, string|CURLFile> $parts
     */
    private function __construct(private array $parts) {}

    public static function new(): self
    {
        return new self([]);
    }

    public function addData(string $name, string $contents, string $filename, ?string $mimeType = null): self
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'tb-http-upload-');
        if ($tempPath === false) {
            throw new InvalidArgumentException('Unable to allocate temporary upload file for multipart data.');
        }

        if (file_put_contents($tempPath, $contents) === false) {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }

            throw new InvalidArgumentException('Unable to write temporary multipart data file.');
        }

        $key = $this->nextPartKey($name);
        $parts = $this->parts;
        $parts[$key] = new CURLFile($tempPath, $mimeType ?? 'application/octet-stream', $filename);

        return new self($parts);
    }

    public function addField(string $name, string|int|float|bool $value): self
    {
        $key = $this->nextPartKey($name);
        $parts = $this->parts;
        $parts[$key] = (string) $value;

        return new self($parts);
    }

    public function addFile(string $name, string $path, ?string $mimeType = null, ?string $postFilename = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Multipart file is missing or unreadable: %s', $path));
        }

        $key = $this->nextPartKey($name);
        $parts = $this->parts;
        $parts[$key] = new CURLFile($path, $mimeType ?? 'application/octet-stream', $postFilename ?? basename($path));

        return new self($parts);
    }

    /**
     * @param resource $stream
     */
    public function addStream(string $name, mixed $stream, string $filename, ?string $mimeType = null): self
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Multipart stream part requires a valid stream resource.');
        }

        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw new InvalidArgumentException('Failed to read multipart stream contents.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'tb-http-upload-');
        if ($tempPath === false) {
            throw new InvalidArgumentException('Unable to allocate temporary upload file for multipart stream.');
        }

        if (file_put_contents($tempPath, $contents) === false) {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }

            throw new InvalidArgumentException('Unable to write temporary multipart stream file.');
        }

        $key = $this->nextPartKey($name);
        $parts = $this->parts;
        $parts[$key] = new CURLFile($tempPath, $mimeType ?? 'application/octet-stream', $filename);

        return new self($parts);
    }

    public function contentType(): string
    {
        return 'multipart/form-data';
    }

    /**
     * @return array<string, string|CURLFile>
     */
    public function toCurlPayload(): array
    {
        return $this->parts;
    }

    private function nextPartKey(string $name): string
    {
        if (!array_key_exists($name, $this->parts)) {
            return $name;
        }

        $index = 0;
        do {
            $key = sprintf('%s[%d]', $name, $index);
            $index++;
        } while (array_key_exists($key, $this->parts));

        return $key;
    }
}
