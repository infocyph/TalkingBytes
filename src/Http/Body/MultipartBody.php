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
        $file = $this->createTemporaryUploadFile(
            contents: $contents,
            filename: $filename,
            mimeType: $mimeType,
            context: 'multipart data',
        );

        return $this->withPart($name, $file);
    }

    public function addField(string $name, string|int|float|bool $value): self
    {
        return $this->withPart($name, (string) $value);
    }

    public function addFile(string $name, string $path, ?string $mimeType = null, ?string $postFilename = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Multipart file is missing or unreadable: %s', $path));
        }

        return $this->withPart(
            $name,
            new CURLFile($path, $mimeType ?? 'application/octet-stream', $postFilename ?? basename($path)),
        );
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

        $file = $this->createTemporaryUploadFile(
            contents: $contents,
            filename: $filename,
            mimeType: $mimeType,
            context: 'multipart stream',
        );

        return $this->withPart($name, $file);
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

    private function createTemporaryUploadFile(
        string $contents,
        string $filename,
        ?string $mimeType,
        string $context,
    ): CURLFile {
        $tempPath = tempnam(sys_get_temp_dir(), 'tb-http-upload-');
        if ($tempPath === false) {
            throw new InvalidArgumentException(sprintf(
                'Unable to allocate temporary upload file for %s.',
                $context,
            ));
        }

        if (file_put_contents($tempPath, $contents) === false) {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }

            throw new InvalidArgumentException(sprintf('Unable to write temporary %s file.', $context));
        }

        return new CURLFile($tempPath, $mimeType ?? 'application/octet-stream', $filename);
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

    private function withPart(string $name, string|CURLFile $value): self
    {
        $key = $this->nextPartKey($name);
        $parts = $this->parts;
        $parts[$key] = $value;

        return new self($parts);
    }
}
