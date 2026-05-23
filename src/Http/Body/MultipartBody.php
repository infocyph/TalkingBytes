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

    public function addField(string $name, string|int|float|bool $value): self
    {
        $parts = $this->parts;
        $parts[$name] = (string) $value;

        return new self($parts);
    }

    public function addFile(string $name, string $path, ?string $mimeType = null, ?string $postFilename = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Multipart file is missing or unreadable: %s', $path));
        }

        $parts = $this->parts;
        $parts[$name] = new CURLFile($path, $mimeType ?? 'application/octet-stream', $postFilename ?? basename($path));

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
}
