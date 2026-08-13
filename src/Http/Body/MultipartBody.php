<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Body;

use CURLFile;
use CURLStringFile;
use InvalidArgumentException;
use Throwable;

final readonly class MultipartBody implements HttpBody
{
    private const int DEFAULT_STREAM_LIMIT = 26_214_400;

    /**
     * @param list<array{
     *   name: string,
     *   type: 'field'|'file'|'data'|'stream',
     *   value?: string,
     *   path?: string,
     *   stream?: resource,
     *   offset?: int,
     *   knownSize?: ?int,
     *   filename?: string,
     *   mimeType?: string
     * }> $parts
     */
    private function __construct(private array $parts) {}

    public static function new(): self
    {
        return new self([]);
    }

    public function addData(string $name, string $contents, string $filename, ?string $mimeType = null): self
    {
        return $this->withPart([
            'name' => self::validateName($name),
            'type' => 'data',
            'value' => $contents,
            'filename' => self::validateFilename($filename),
            'mimeType' => $mimeType ?? 'application/octet-stream',
        ]);
    }

    public function addField(string $name, string|int|float|bool $value): self
    {
        return $this->withPart([
            'name' => self::validateName($name),
            'type' => 'field',
            'value' => (string) $value,
        ]);
    }

    public function addFile(string $name, string $path, ?string $mimeType = null, ?string $postFilename = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Multipart file is missing or unreadable: %s', $path));
        }

        return $this->withPart([
            'name' => self::validateName($name),
            'type' => 'file',
            'path' => $path,
            'filename' => self::validateFilename($postFilename ?? basename($path)),
            'mimeType' => $mimeType ?? 'application/octet-stream',
        ]);
    }

    /** @param resource $stream */
    public function addStream(
        string $name,
        mixed $stream,
        string $filename,
        ?string $mimeType = null,
        ?int $knownSize = null,
    ): self {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Multipart stream part requires a valid stream resource.');
        }

        $metadata = stream_get_meta_data($stream);
        if (!$metadata['seekable']) {
            throw new InvalidArgumentException('Multipart stream parts must be seekable for repeatable sends.');
        }

        $offset = ftell($stream);
        if (!is_int($offset)) {
            throw new InvalidArgumentException('Unable to determine multipart stream position.');
        }

        if ($knownSize !== null && $knownSize < 0) {
            throw new InvalidArgumentException('Multipart stream known size cannot be negative.');
        }

        return $this->withPart([
            'name' => self::validateName($name),
            'type' => 'stream',
            'stream' => $stream,
            'offset' => $offset,
            'knownSize' => $knownSize,
            'filename' => self::validateFilename($filename),
            'mimeType' => $mimeType ?? 'application/octet-stream',
        ]);
    }

    public function contentType(): string
    {
        return 'multipart/form-data';
    }

    /**
     * @return array{payload: array<string, string|CURLFile|CURLStringFile>, temporaryPaths: list<string>}
     */
    public function prepareCurlPayload(?int $maxUploadBytes = null): array
    {
        $payload = [];
        $temporaryPaths = [];
        $contentBytes = 0;

        try {
            foreach ($this->parts as $part) {
                $key = self::nextPartKey($payload, $part['name']);
                if ($part['type'] === 'field') {
                    $value = $part['value'] ?? '';
                    $contentBytes += strlen($value);
                    $payload[$key] = $value;

                    continue;
                }

                if ($part['type'] === 'file') {
                    $path = $part['path'] ?? '';
                    $size = filesize($path);
                    if (!is_int($size)) {
                        throw new InvalidArgumentException(sprintf('Unable to determine multipart file size: %s', $path));
                    }
                    $contentBytes += $size;
                    $payload[$key] = new CURLFile($path, $part['mimeType'] ?? '', $part['filename'] ?? 'upload');

                    continue;
                }

                if ($part['type'] === 'data') {
                    $value = $part['value'] ?? '';
                    $contentBytes += strlen($value);
                    $payload[$key] = new CURLStringFile($value, $part['filename'] ?? 'upload', $part['mimeType'] ?? 'application/octet-stream');

                    continue;
                }

                $path = $this->spoolStreamPart($part, $maxUploadBytes, $contentBytes);
                $temporaryPaths[] = $path;
                $payload[$key] = new CURLFile($path, $part['mimeType'] ?? '', $part['filename'] ?? 'upload');
            }

            if ($maxUploadBytes !== null && $contentBytes > $maxUploadBytes) {
                throw new InvalidArgumentException(sprintf('HTTP multipart content exceeded max upload bytes (%d).', $maxUploadBytes));
            }

            return ['payload' => $payload, 'temporaryPaths' => $temporaryPaths];
        } catch (Throwable $throwable) {
            self::cleanup($temporaryPaths);

            throw $throwable;
        }
    }

    /** @return array<string, string|CURLFile|CURLStringFile> */
    public function toCurlPayload(): array
    {
        if (array_any($this->parts, static fn(array $part): bool => $part['type'] === 'stream')) {
            throw new InvalidArgumentException(
                'Multipart stream bodies must be prepared by the HTTP transport so temporary resources can be cleaned.',
            );
        }

        return $this->prepareCurlPayload()['payload'];
    }

    /** @param list<string> $paths */
    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @param array<string, string|CURLFile|CURLStringFile> $parts */
    private static function nextPartKey(array $parts, string $name): string
    {
        if (!isset($parts[$name])) {
            return $name;
        }

        $index = 0;
        do {
            $key = sprintf('%s[%d]', $name, $index++);
        } while (isset($parts[$key]));

        return $key;
    }

    private static function validateFilename(string $filename): string
    {
        if ($filename === '' || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1) {
            throw new InvalidArgumentException('Multipart filename is empty or contains control characters.');
        }

        return $filename;
    }

    private static function validateName(string $name): string
    {
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new InvalidArgumentException('Multipart field name is empty or contains control characters.');
        }

        return $name;
    }

    /**
     * @param array{name: string, type: 'field'|'file'|'data'|'stream', value?: string, path?: string, stream?: resource, offset?: int, knownSize?: ?int, filename?: string, mimeType?: string} $part
     */
    private function spoolStreamPart(array $part, ?int $maxUploadBytes, int &$contentBytes): string
    {
        $stream = $part['stream'] ?? null;
        $offset = $part['offset'] ?? null;
        if (!is_resource($stream) || !is_int($offset) || fseek($stream, $offset) !== 0) {
            throw new InvalidArgumentException('Unable to rewind multipart stream part.');
        }

        $remaining = $maxUploadBytes === null
            ? self::DEFAULT_STREAM_LIMIT
            : max(0, $maxUploadBytes - $contentBytes);
        $knownSize = $part['knownSize'] ?? null;
        if (is_int($knownSize) && $knownSize > $remaining) {
            throw new InvalidArgumentException(sprintf('HTTP multipart content exceeded max upload bytes (%d).', $remaining));
        }

        $path = tempnam(sys_get_temp_dir(), 'tb-http-upload-');
        if ($path === false) {
            throw new InvalidArgumentException('Unable to allocate temporary multipart stream file.');
        }
        chmod($path, 0600);

        $destination = fopen($path, 'wb');
        if ($destination === false) {
            unlink($path);

            throw new InvalidArgumentException('Unable to open temporary multipart stream file.');
        }

        try {
            try {
                $readLimit = $remaining < PHP_INT_MAX ? $remaining + 1 : PHP_INT_MAX;
                $copied = stream_copy_to_stream($stream, $destination, $readLimit);
                if (!is_int($copied) || $copied > $remaining) {
                    throw new InvalidArgumentException('Multipart stream exceeded its configured byte limit.');
                }
                if (is_int($knownSize) && $copied !== $knownSize) {
                    throw new InvalidArgumentException('Multipart stream byte count did not match its known size.');
                }
                $contentBytes += $copied;
                if (!fflush($destination)) {
                    throw new InvalidArgumentException('Unable to flush temporary multipart stream file.');
                }
            } finally {
                fclose($destination);
                fseek($stream, $offset);
            }
        } catch (Throwable $throwable) {
            unlink($path);

            throw $throwable;
        }

        return $path;
    }

    /**
     * @param array{name: string, type: 'field'|'file'|'data'|'stream', value?: string, path?: string, stream?: resource, offset?: int, knownSize?: ?int, filename?: string, mimeType?: string} $part
     */
    private function withPart(array $part): self
    {
        return new self([...$this->parts, $part]);
    }
}
