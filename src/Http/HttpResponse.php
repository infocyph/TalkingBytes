<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use JsonException;

final readonly class HttpResponse
{
    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ?int $statusCode,
        public string $body,
        public array $headers = [],
        public array $metadata = [],
    ) {}

    public function clientError(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function failed(): bool
    {
        return !$this->ok();
    }

    /**
     * @return string|list<string>|null
     */
    public function header(string $name): string|array|null
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function headerLine(string $name): ?string
    {
        $value = $this->header($name);

        if ($value === null) {
            return null;
        }

        return is_array($value) ? implode(', ', $value) : $value;
    }

    /**
     * @throws JsonException
     */
    public function json(bool $associative = true): mixed
    {
        return json_decode($this->body, $associative, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    public function jsonOrFail(bool $associative = true): mixed
    {
        return $this->json($associative);
    }

    public function jsonOrNull(bool $associative = true): mixed
    {
        try {
            return $this->json($associative);
        } catch (JsonException) {
            return null;
        }
    }

    public function ok(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function redirect(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 300 && $this->statusCode < 400;
    }

    public function serverError(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 500;
    }

    public function successful(): bool
    {
        return $this->ok();
    }
}
