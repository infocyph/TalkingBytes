<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

final readonly class AuthenticationCheckResult
{
    /**
     * @param array<string, string> $properties
     */
    public function __construct(
        public string $method,
        public string $result,
        public ?string $domain = null,
        public ?string $selector = null,
        public ?string $scope = null,
        public array $properties = [],
    ) {}

    public function passed(): bool
    {
        return strtolower($this->result) === 'pass';
    }
}
