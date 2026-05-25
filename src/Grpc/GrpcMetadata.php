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

    public function first(string $name): ?string
    {
        $values = $this->values($name);

        return $values[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function values(string $name): array
    {
        /** @var list<string> $values */
        $values = $this->headers[self::normalizeHeaderName($name)] ?? [];

        return $values;
    }

    /**
     * @param list<string> $values
     */
    public function with(string $name, array $values): self
    {
        $normalizedName = self::normalizeHeaderName($name);
        foreach ($values as $value) {
            self::assertHeaderValue($value, $normalizedName);
        }

        $headers = $this->headers;
        $headers[$normalizedName] = $values;

        return new self($headers);
    }

    public function withValue(string $name, string $value): self
    {
        $normalizedName = self::normalizeHeaderName($name);
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
        if (str_ends_with($name, '-bin')) {
            throw new InvalidArgumentException(sprintf(
                'Binary gRPC metadata key "%s" is not supported yet.',
                $name,
            ));
        }

        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0")) {
            throw new InvalidArgumentException('gRPC metadata value must not contain control characters.');
        }
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
