<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use InvalidArgumentException;

final readonly class HeaderBag
{
    /**
     * @var array<string, string|list<string>>
     */
    private array $headers;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(array $headers = [])
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalizedName = $this->normalize($name);
            self::assertValidHeaderName($normalizedName);

            if (is_array($value)) {
                foreach ($value as $singleValue) {
                    self::assertValidHeaderValue($singleValue);
                }

                $normalized[$normalizedName] = $value;

                continue;
            }

            self::assertValidHeaderValue($value);
            $normalized[$normalizedName] = $value;
        }

        $this->headers = $normalized;
    }

    public static function assertValidHeaderName(string $name): void
    {
        if ($name === '' || preg_match('/^[!#$%&\'\*+\-.\^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid HTTP header name: %s', $name));
        }
    }

    public static function assertValidHeaderValue(string $value): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidArgumentException('HTTP header value must not contain CRLF characters.');
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('HTTP header value contains invalid control characters.');
        }
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function all(): array
    {
        return $this->headers;
    }

    /**
     * @return string|list<string>|null
     */
    public function get(string $name): string|array|null
    {
        $normalized = $this->normalize($name);

        return $this->headers[$normalized] ?? null;
    }

    /**
     * @return list<string>
     */
    public function toCurlHeaders(): array
    {
        $lines = [];

        foreach ($this->headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $singleValue) {
                    $lines[] = sprintf('%s: %s', $name, $singleValue);
                }

                continue;
            }

            $lines[] = sprintf('%s: %s', $name, $value);
        }

        return $lines;
    }

    /**
     * @param string|list<string> $value
     */
    public function with(string $name, string|array $value): self
    {
        $normalizedName = $this->normalize($name);
        self::assertValidHeaderName($normalizedName);

        if (is_array($value)) {
            foreach ($value as $singleValue) {
                self::assertValidHeaderValue($singleValue);
            }
        } else {
            self::assertValidHeaderValue($value);
        }

        $headers = $this->headers;
        $headers[$normalizedName] = $value;

        return new self($headers);
    }

    public function without(string $name): self
    {
        $headers = $this->headers;
        unset($headers[$this->normalize($name)]);

        return new self($headers);
    }

    private function normalize(string $name): string
    {
        return implode('-', array_map(ucfirst(...), explode('-', strtolower(trim($name)))));
    }
}
