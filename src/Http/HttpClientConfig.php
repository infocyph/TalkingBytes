<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Infocyph\TalkingBytes\Http\Options\CurlOptions;
use Infocyph\TalkingBytes\Http\Support\HeaderBag;
use InvalidArgumentException;

final readonly class HttpClientConfig
{
    /**
     * @param array<string, string|list<string>> $defaultHeaders
     */
    public function __construct(
        public int $timeoutSeconds = 10,
        public int $connectTimeoutSeconds = 10,
        public bool $followRedirects = false,
        public int $maxRedirects = 5,
        public bool $verifyPeer = true,
        public bool $verifyHost = true,
        public ?string $caBundle = null,
        public ?string $proxy = null,
        public ?string $proxyUsername = null,
        public ?string $proxyPassword = null,
        public ?string $userAgent = null,
        public ?int $maxResponseBytes = null,
        public array $defaultHeaders = [],
    ) {
        new CurlOptions(
            timeoutSeconds: $this->timeoutSeconds,
            connectTimeoutSeconds: $this->connectTimeoutSeconds,
            followRedirects: $this->followRedirects,
            maxRedirects: $this->maxRedirects,
            proxy: $this->proxy,
            verifyPeer: $this->verifyPeer,
            verifyHost: $this->verifyHost,
            caBundle: $this->caBundle,
            userAgent: $this->userAgent,
            maxResponseBytes: $this->maxResponseBytes,
        );

        new HeaderBag($this->defaultHeaders);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            timeoutSeconds: self::intFrom($config, 'timeoutSeconds', 10),
            connectTimeoutSeconds: self::intFrom($config, 'connectTimeoutSeconds', 10),
            followRedirects: self::boolFrom($config, 'followRedirects', false),
            maxRedirects: self::intFrom($config, 'maxRedirects', 5),
            verifyPeer: self::boolFrom($config, 'verifyPeer', true),
            verifyHost: self::boolFrom($config, 'verifyHost', true),
            caBundle: is_string($config['caBundle'] ?? null) ? $config['caBundle'] : null,
            proxy: is_string($config['proxy'] ?? null) ? $config['proxy'] : null,
            proxyUsername: is_string($config['proxyUsername'] ?? null) ? $config['proxyUsername'] : null,
            proxyPassword: is_string($config['proxyPassword'] ?? null) ? $config['proxyPassword'] : null,
            userAgent: is_string($config['userAgent'] ?? null) ? $config['userAgent'] : null,
            maxResponseBytes: isset($config['maxResponseBytes']) ? self::intFrom($config, 'maxResponseBytes', 0) : null,
            defaultHeaders: self::parseDefaultHeaders($config['defaultHeaders'] ?? null),
        );
    }

    /** @param array<string, mixed> $config */
    private static function boolFrom(array $config, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        $value = filter_var($config[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new InvalidArgumentException(sprintf('HTTP configuration key "%s" must be a boolean.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function intFrom(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($parsed)) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException(sprintf('HTTP configuration key "%s" must be an integer.', $key));
    }

    /**
     * @return array<string, string|list<string>>
     */
    private static function parseDefaultHeaders(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $defaultHeaders = [];
        foreach ($value as $name => $headerValue) {
            if (!is_string($name)) {
                continue;
            }

            if (is_string($headerValue)) {
                $defaultHeaders[$name] = $headerValue;

                continue;
            }

            if (!is_array($headerValue)) {
                continue;
            }

            $items = [];
            foreach ($headerValue as $item) {
                if (is_string($item)) {
                    $items[] = $item;
                }
            }

            if ($items !== []) {
                $defaultHeaders[$name] = $items;
            }
        }

        return $defaultHeaders;
    }
}
