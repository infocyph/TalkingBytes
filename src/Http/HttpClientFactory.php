<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\Cookie\CookieJar;
use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use InvalidArgumentException;

final readonly class HttpClientFactory
{
    public function __construct(
        private ?EventDispatcher $events = null,
        private ?CancellationSignal $cancellation = null,
        private ?Clock $clock = null,
    ) {}

    /**
     * Build an HTTP client from already-resolved protocol configuration.
     *
     * @param array<string, mixed> $config
     */
    public function fromArray(array $config, ?HttpTransport $transport = null): HttpClient
    {
        $client = HttpClient::fromConfig(
            HttpClientConfig::fromArray($config),
            $this->events,
            $transport,
            $this->clock,
        );

        $client = $this->applyAuth($client, self::section($config, 'auth'));

        if (self::enabled(self::section($config, 'cookies'))) {
            $client = $client->withCookieJar(new CookieJar());
        }

        $retry = self::section($config, 'retry');
        if (self::enabled($retry)) {
            $client = $client->withHttpRetry(
                HttpRetryPolicy::standard(
                    self::int($retry, 'attempts', 3),
                    self::int($retry, 'base_delay_ms', 250),
                    self::int($retry, 'max_retry_after_seconds', 30),
                ),
                $this->cancellation,
            );
        }

        $rateLimit = self::section($config, 'rate_limit');
        if (self::enabled($rateLimit)) {
            $client = $client->withRateLimit(new RateLimiter(
                self::int($rateLimit, 'max_requests', 60),
                self::int($rateLimit, 'per_seconds', 60),
            ));
        }

        $circuit = self::section($config, 'circuit_breaker');
        if (self::enabled($circuit)) {
            $client = $client->withCircuitBreaker(new CircuitBreaker(
                self::int($circuit, 'failure_threshold', 5),
                self::int($circuit, 'cool_down_seconds', 30),
            ));
        }

        $idempotency = self::section($config, 'idempotency');
        if (self::enabled($idempotency)) {
            $client = $client->withIdempotency(
                self::string($idempotency, 'header', 'Idempotency-Key', true),
            );
        }

        return $client;
    }

    /** @param array<string, mixed> $config */
    private static function bool(array $config, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        $value = $config[$key];
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if (is_bool($parsed)) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException(sprintf('HTTP resolved configuration key "%s" must be a boolean.', $key));
    }

    /** @param array<string, mixed> $config */
    private static function enabled(array $config): bool
    {
        return self::bool($config, 'enabled', false);
    }

    /** @param array<string, mixed> $config */
    private static function int(array $config, string $key, int $default): int
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

        throw new InvalidArgumentException(sprintf('HTTP resolved configuration key "%s" must be an integer.', $key));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function section(array $config, string $key): array
    {
        $value = $config[$key] ?? [];
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('HTTP resolved configuration section "%s" must be an array.', $key));
        }

        $section = [];
        foreach ($value as $name => $item) {
            if (is_string($name)) {
                $section[$name] = $item;
            }
        }

        return $section;
    }

    /** @param array<string, mixed> $config */
    private static function string(
        array $config,
        string $key,
        string $default = '',
        bool $required = false,
    ): string {
        $value = $config[$key] ?? $default;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('HTTP resolved configuration key "%s" must be a string.', $key));
        }

        $value = trim($value);
        if ($required && $value === '') {
            throw new InvalidArgumentException(sprintf('HTTP resolved configuration key "%s" must be non-empty.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $auth
     */
    private function applyAuth(HttpClient $client, array $auth): HttpClient
    {
        return match (self::string($auth, 'driver', 'none')) {
            'api_key', 'api_key_header', 'api-key-header', 'header' => $client->withApiKeyHeader(
                self::string($auth, 'header', 'X-Api-Key', true),
                self::string($auth, 'value', '', true),
            ),
            'api_key_query', 'api-key-query', 'query' => $client->withApiKeyQuery(
                self::string($auth, 'query_key', 'api_key', true),
                self::string($auth, 'value', '', true),
            ),
            'basic' => $client->withBasicAuth(
                self::string($auth, 'username', '', true),
                self::string($auth, 'password', '', true),
            ),
            'bearer' => $client->withBearerToken(self::string($auth, 'token', '', true)),
            'none', '' => $client,
            default => throw new InvalidArgumentException('Unsupported HTTP auth driver.'),
        };
    }
}
