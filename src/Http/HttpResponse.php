<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Infocyph\TalkingBytes\Http\Stats\HttpTransferStats;
use JsonException;

final readonly class HttpResponse
{
    /** @var array<string, string|list<string>> */
    public array $headers;

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ?int $statusCode,
        public string $body,
        array $headers = [],
        public array $metadata = [],
    ) {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $key = strtolower($name);
            $incoming = is_array($values) ? $values : [$values];
            if (!isset($normalized[$key])) {
                $normalized[$key] = $incoming;

                continue;
            }

            $normalized[$key] = [...$normalized[$key], ...$incoming];
        }

        foreach ($normalized as $name => $values) {
            $normalized[$name] = count($values) === 1 ? $values[0] : $values;
        }

        $this->headers = $normalized;
    }

    public function accepted(): bool
    {
        return $this->statusCode === 202;
    }

    public function clientError(): bool
    {
        return $this->isClientError();
    }

    public function created(): bool
    {
        return $this->statusCode === 201;
    }

    public function failed(): bool
    {
        return $this->isClientError() || $this->isServerError();
    }

    /**
     * @return string|list<string>|null
     */
    public function header(string $name): string|array|null
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function headerLine(string $name): ?string
    {
        $value = $this->header($name);

        if ($value === null) {
            return null;
        }

        return is_array($value) ? implode(', ', $value) : $value;
    }

    public function isClientError(): bool
    {
        return $this->statusGroup() === 4;
    }

    public function isInformational(): bool
    {
        return $this->statusGroup() === 1;
    }

    public function isRedirection(): bool
    {
        return $this->statusGroup() === 3;
    }

    public function isServerError(): bool
    {
        return $this->statusGroup() === 5;
    }

    public function isSuccessful(): bool
    {
        return $this->statusGroup() === 2;
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

    public function noContent(): bool
    {
        return $this->statusCode === 204;
    }

    public function ok(): bool
    {
        return $this->isSuccessful();
    }

    public function redirect(): bool
    {
        return $this->isRedirection();
    }

    public function serverError(): bool
    {
        return $this->isServerError();
    }

    public function stats(): HttpTransferStats
    {
        $curlInfo = $this->metadata['curl'] ?? [];
        if (!is_array($curlInfo)) {
            $curlInfo = [];
        }

        /** @var array<string, mixed> $info */
        $info = $curlInfo;

        return HttpTransferStats::fromCurlInfo($info);
    }

    public function statusGroup(): ?int
    {
        if ($this->statusCode === null || $this->statusCode < 100) {
            return null;
        }

        $group = intdiv($this->statusCode, 100);
        if ($group < 1 || $group > 5) {
            return null;
        }

        return $group;
    }

    public function successful(): bool
    {
        return $this->isSuccessful();
    }

    public function text(): string
    {
        return $this->body;
    }
}
