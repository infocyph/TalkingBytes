<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use InvalidArgumentException;

final class GrpcMetadata
{
    /** @var array<string, list<string>> */
    public array $headers;

    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(array $headers = [])
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalizedName = self::normalizeHeaderName($name);

            foreach ($values as $value) {
                self::assertHeaderValue($value, $normalizedName);
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
        return array_key_exists(self::normalizeHeaderName($name), $this->headers);
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

        $headers = $this->headers;
        /** @var list<string> $values */
        $values = $headers[$normalizedName] ?? [];
        $values[] = $value;
        $headers[$normalizedName] = $values;

        return new self($headers);
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

        $headers = $this->headers;
        /** @var list<string> $values */
        $values = $headers[$normalizedName] ?? [];
        $values[] = $value;
        $headers[$normalizedName] = $values;

        return new self($headers);
    }

    private static function assertHeaderValue(string $value, string $name): void
    {
        if (self::isBinaryHeader($name)) {
            return;
        }

        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0")) {
            throw new InvalidArgumentException('gRPC metadata value must not contain control characters.');
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

        if (preg_match('/^[a-z0-9_.-]+$/', $normalized) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid gRPC metadata name "%s".', $name));
        }

        return $normalized;
    }
}
