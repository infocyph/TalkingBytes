<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Support;

use InvalidArgumentException;

final readonly class QueryParams
{
    /**
     * @param array<string, scalar|list<scalar>|null> $params
     */
    public function __construct(private array $params = [])
    {
        foreach ($params as $name => $value) {
            $this->assertValidName($name);
            $this->normalizeValue($value);
        }
    }

    /**
     * @return array<string, scalar|list<scalar>|null>
     */
    public function all(): array
    {
        return $this->params;
    }

    public function toQueryString(): string
    {
        $flattened = [];
        foreach ($this->params as $key => $value) {
            if ($value === null) {
                continue;
            }

            $flattened[$key] = $this->normalizeValue($value);
        }

        return http_build_query($flattened, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param scalar|list<scalar>|null $value
     */
    public function with(string $name, mixed $value): self
    {
        $this->assertValidName($name);
        $params = $this->params;
        $params[$name] = $value === null ? null : $this->normalizeValue($value);
        /** @var array<string, scalar|list<scalar>|null> $params */

        return new self($params);
    }

    public function without(string $name): self
    {
        $params = $this->params;
        unset($params[$name]);

        return new self($params);
    }

    private function assertValidName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Query parameter name must not be empty.');
        }
    }

    /**
     * @param array<mixed> $values
     * @return list<int|float|string>
     */
    private function normalizeList(array $values): array
    {
        $normalized = [];
        foreach ($values as $item) {
            if (is_bool($item)) {
                $normalized[] = $item ? 1 : 0;

                continue;
            }

            if (is_int($item) || is_float($item) || is_string($item)) {
                $normalized[] = $item;

                continue;
            }

            throw new InvalidArgumentException('Query list values must be scalar.');
        }

        return $normalized;
    }

    /**
     * @return int|float|string|list<int|float|string>|null
     */
    private function normalizeValue(mixed $value): int|float|string|array|null
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

        if (is_array($value)) {
            return $this->normalizeList($value);
        }

        throw new InvalidArgumentException('Query parameter values must be scalar, list<scalar>, or null.');
    }
}
