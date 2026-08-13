<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use InvalidArgumentException;

final readonly class GrpcMetadata
{
    private const int MAX_COUNT = 64;

    private const int MAX_KEY_BYTES = 128;

    private const int MAX_TOTAL_BYTES = 32_768;

    private const int MAX_VALUE_BYTES = 8_192;

    /** @var array<string, list<string>> */
    public array $headers;

    /**
     * @param array<array-key, mixed> $headers
     */
    public function __construct(array $headers = [])
    {
        if (count($headers) > self::MAX_COUNT) {
            throw new InvalidArgumentException(sprintf('gRPC metadata cannot contain more than %d keys.', self::MAX_COUNT));
        }

        $normalized = [];
        $totalBytes = 0;
        foreach ($headers as $name => $values) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('gRPC metadata names must be strings.');
            }

            if (!is_array($values) || !array_is_list($values)) {
                throw new InvalidArgumentException('gRPC metadata values must be provided as lists of strings.');
            }

            $normalizedName = self::normalizeHeaderName($name);

            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('gRPC metadata values must be strings.');
                }
                self::assertHeaderValue($value, $normalizedName);
                $totalBytes += strlen($normalizedName) + strlen($value);
                if ($totalBytes > self::MAX_TOTAL_BYTES) {
                    throw new InvalidArgumentException(sprintf(
                        'gRPC metadata cannot exceed %d total bytes.',
                        self::MAX_TOTAL_BYTES,
                    ));
                }
            }

            $normalized[$normalizedName] = $values;
        }

        $this->headers = $normalized;
    }

    /**
     * @return list<string>
     */
    public function binaryValues(string $name): array
    {
        $normalizedName = self::normalizeHeaderName($name);
        if (!self::isBinaryHeader($normalizedName)) {
            throw new InvalidArgumentException(sprintf(
                'Metadata key "%s" is not a binary key; use values() instead.',
                $normalizedName,
            ));
        }

        /** @var list<string> $values */
        $values = $this->headers[$normalizedName] ?? [];

        return $values;
    }

    public function first(string $name): ?string
    {
        $values = $this->values($name);

        return $values[0] ?? null;
    }

    public function firstBinary(string $name): ?string
    {
        $values = $this->binaryValues($name);

        return $values[0] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->headers[self::normalizeHeaderName($name)]);
    }

    /**
     * @return list<string>
     */
    public function values(string $name): array
    {
        $normalizedName = self::normalizeHeaderName($name);
        if (self::isBinaryHeader($normalizedName)) {
            throw new InvalidArgumentException(sprintf(
                'Binary gRPC metadata key "%s" requires binaryValues() accessor.',
                $normalizedName,
            ));
        }

        /** @var list<string> $values */
        $values = $this->headers[$normalizedName] ?? [];

        return $values;
    }

    /**
     * @param list<string> $values
     */
    public function with(string $name, array $values): self
    {
        $normalizedName = self::normalizeHeaderName($name);
        if (self::isBinaryHeader($normalizedName)) {
            throw new InvalidArgumentException(sprintf(
                'Binary gRPC metadata key "%s" requires withBinary() or withBinaryValue().',
                $normalizedName,
            ));
        }

        foreach ($values as $value) {
            self::assertHeaderValue($value, $normalizedName);
        }

        $headers = $this->headers;
        $headers[$normalizedName] = $values;

        return new self($headers);
    }

    /**
     * @param list<string> $values
     */
    public function withBinary(string $name, array $values): self
    {
        $normalizedName = self::normalizeHeaderName($name);
        if (!self::isBinaryHeader($normalizedName)) {
            throw new InvalidArgumentException(sprintf(
                'Metadata key "%s" is not a binary key; use with() instead.',
                $normalizedName,
            ));
        }

        $headers = $this->headers;
        $headers[$normalizedName] = $values;

        return new self($headers);
    }

    public function withBinaryValue(string $name, string $value): self
    {
        $normalizedName = self::normalizeHeaderName($name);
        if (!self::isBinaryHeader($normalizedName)) {
            throw new InvalidArgumentException(sprintf(
                'Metadata key "%s" is not a binary key; use withValue() instead.',
                $normalizedName,
            ));
        }

        return $this->appendValue($normalizedName, $value);
    }

    public function withValue(string $name, string $value): self
    {
        $normalizedName = self::normalizeHeaderName($name);
        if (self::isBinaryHeader($normalizedName)) {
            throw new InvalidArgumentException(sprintf(
                'Binary gRPC metadata key "%s" requires withBinaryValue().',
                $normalizedName,
            ));
        }

        self::assertHeaderValue($value, $normalizedName);

        return $this->appendValue($normalizedName, $value);
    }

    private static function assertHeaderValue(string $value, string $name): void
    {
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'gRPC metadata values cannot exceed %d bytes.',
                self::MAX_VALUE_BYTES,
            ));
        }

        if (self::isBinaryHeader($name)) {
            return;
        }

        if (preg_match('/[^\x20-\x7E]/', $value) === 1) {
            throw new InvalidArgumentException('Non-binary gRPC metadata values must contain only printable ASCII bytes.');
        }
    }

    private static function isBinaryHeader(string $name): bool
    {
        return str_ends_with($name, '-bin');
    }

    private static function normalizeHeaderName(string $name): string
    {
        $normalized = strtolower($name);

        if ($normalized === '') {
            throw new InvalidArgumentException('gRPC metadata name must not be empty.');
        }

        if (strlen($normalized) > self::MAX_KEY_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'gRPC metadata names cannot exceed %d bytes.',
                self::MAX_KEY_BYTES,
            ));
        }

        if (preg_match('/^[a-z0-9_.-]+$/', $normalized) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid gRPC metadata name "%s".', $name));
        }

        return $normalized;
    }

    private function appendValue(string $normalizedName, string $value): self
    {
        $headers = $this->headers;
        /** @var list<string> $values */
        $values = $headers[$normalizedName] ?? [];
        $values[] = $value;
        $headers[$normalizedName] = $values;

        return new self($headers);
    }
}
