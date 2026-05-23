<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

final readonly class QueryParams
{
    /**
     * @param array<string, scalar|list<scalar>|null> $params
     */
    public function __construct(private array $params = []) {}

    /**
     * @return array<string, scalar|list<scalar>|null>
     */
    public function all(): array
    {
        return $this->params;
    }

    public function toQueryString(): string
    {
        return http_build_query($this->params);
    }

    /**
     * @param scalar|list<scalar>|null $value
     */
    public function with(string $name, mixed $value): self
    {
        $params = $this->params;
        $params[$name] = $value;

        return new self($params);
    }

    public function without(string $name): self
    {
        $params = $this->params;
        unset($params[$name]);

        return new self($params);
    }
}
