<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Support;

use InvalidArgumentException;

final readonly class QueryParams
{
    /** @var list<array{name: string, value: int|float|string|null}> */
    private array $entries;

    /**
     * @param array<string, scalar|list<scalar>|null>|list<array{name: string, value: int|float|string|null}> $params
     * @internal The normalized flag is reserved for immutable copies.
     */
    public function __construct(array $params = [], bool $normalized = false)
    {
        if ($normalized) {
            /** @var list<array{name: string, value: int|float|string|null}> $params */
            $this->entries = $params;

            return;
        }

        $entries = [];
        foreach ($params as $name => $value) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Query parameter names must be strings.');
            }
            self::appendNormalized($entries, $name, $value);
        }
        $this->entries = $entries;
    }

    /** @return list<array{name: string, value: int|float|string|null}> */
    public function all(): array
    {
        return $this->entries;
    }

    public function append(string $name, mixed $value): self
    {
        $entries = $this->entries;
        self::appendNormalized($entries, $name, $value);

        return self::hydrate($entries);
    }

    public function applyTo(string $existingQuery): string
    {
        $overridden = [];
        foreach ($this->entries as $entry) {
            $overridden[$entry['name']] = true;
        }

        $parts = [];
        if ($existingQuery !== '') {
            foreach (explode('&', $existingQuery) as $part) {
                $encodedName = explode('=', $part, 2)[0];
                if (!isset($overridden[rawurldecode($encodedName)])) {
                    $parts[] = $part;
                }
            }
        }

        foreach ($this->entries as $entry) {
            if ($entry['value'] === null) {
                continue;
            }

            $parts[] = rawurlencode($entry['name']) . '=' . rawurlencode((string) $entry['value']);
        }

        return implode('&', $parts);
    }

    public function toQueryString(): string
    {
        return $this->applyTo('');
    }

    /** @param scalar|list<scalar>|null $value */
    public function with(string $name, mixed $value): self
    {
        self::assertValidName($name);
        $entries = array_values(array_filter(
            $this->entries,
            static fn(array $entry): bool => $entry['name'] !== $name,
        ));
        self::appendNormalized($entries, $name, $value);

        return self::hydrate($entries);
    }

    public function without(string $name): self
    {
        return $this->with($name, null);
    }

    /** @param list<array{name: string, value: int|float|string|null}> $entries */
    private static function appendNormalized(array &$entries, string $name, mixed $value): void
    {
        self::assertValidName($name);

        if (is_array($value)) {
            foreach ($value as $item) {
                $entries[] = ['name' => $name, 'value' => self::normalizeScalar($item)];
            }

            return;
        }

        $entries[] = ['name' => $name, 'value' => self::normalizeScalar($value)];
    }

    private static function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Query parameter name must not be empty.');
        }
    }

    /** @param list<array{name: string, value: int|float|string|null}> $entries */
    private static function hydrate(array $entries): self
    {
        return new self($entries, true);
    }

    private static function normalizeScalar(mixed $value): int|float|string|null
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Query parameter values must be scalar, list<scalar>, or null.');
    }
}
