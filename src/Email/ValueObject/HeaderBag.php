<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

final readonly class HeaderBag
{
    /**
     * @param array<string, list<string>> $headers
     * @param list<string> $rawLines
     */
    public function __construct(
        private array $headers,
        private array $rawLines = [],
    ) {}

    /**
     * @return list<string>
     */
    public function all(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function asMap(): array
    {
        return $this->headers;
    }

    public function first(string $name): ?string
    {
        $values = $this->all($name);

        return $values[0] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->headers);
    }

    /**
     * @return list<string>
     */
    public function rawLines(): array
    {
        return $this->rawLines;
    }
}
