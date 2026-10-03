<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Core\Support\ResolvedConfig;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\Cookie\CookieJar;
use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;
use Infocyph\TalkingBytes\Integration\Runwire\RunwireBinding;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use InvalidArgumentException;

final readonly class HttpClientFactory
{
    public function __construct(
        private ?EventDispatcher $events = null,
        private ?CancellationSignal $cancellation = null,
        private ?Clock $clock = null,
        private ?Sleeper $sleeper = null,
        private ?OperationDeadline $operationDeadline = null,
        private ?RunwireBinding $runwireBinding = null,
    ) {}

    /**
     * Build an HTTP client from already-resolved protocol configuration.
     *
     * @param array<string, mixed> $config
     */
    public function fromArray(array $config, ?HttpTransport $transport = null): HttpClient
    {
        return $this->fromConfig(
            HttpClientConfig::fromArray($config),
            $config,
            $transport,
        );
    }

    /**
     * Compose resolved protocol behavior around an already-parsed base HTTP configuration.
     *
     * Base HTTP options are taken exclusively from $baseConfig. The resolved
     * array supplies auth, cookies, retry, rate limiting, circuit breaking,
     * and idempotency sections without reparsing the base configuration.
     *
     * @param array<string, mixed> $config
     */
    public function fromConfig(
        HttpClientConfig $baseConfig,
        array $config = [],
        ?HttpTransport $transport = null,
    ): HttpClient {
        $client = HttpClient::fromConfig(
            $baseConfig,
            $this->events,
            $transport,
            $this->clock,
        );

        $client = $this->applyAuth($client, ResolvedConfig::section($config, 'auth', 'HTTP'));

        if ($this->cancellation !== null) {
            $client = $client->withCancellation($this->cancellation);
        }

        if ($this->operationDeadline !== null) {
            $client = $client->withOperationDeadline($this->operationDeadline);
        }

        if (ResolvedConfig::bool(ResolvedConfig::section($config, 'cookies', 'HTTP'), 'enabled', false, 'HTTP')) {
            $client = $client->withCookieJar(new CookieJar());
        }

        $retry = ResolvedConfig::section($config, 'retry', 'HTTP');
        if (ResolvedConfig::bool($retry, 'enabled', false, 'HTTP')) {
            $client = $client->withHttpRetry(
                HttpRetryPolicy::standard(
                    ResolvedConfig::int($retry, 'attempts', 3, 'HTTP'),
                    ResolvedConfig::int($retry, 'base_delay_ms', 250, 'HTTP'),
                    ResolvedConfig::int($retry, 'max_retry_after_seconds', 30, 'HTTP'),
                ),
                $this->cancellation,
                $this->sleeper,
            );
        }

        $rateLimit = ResolvedConfig::section($config, 'rate_limit', 'HTTP');
        if (ResolvedConfig::bool($rateLimit, 'enabled', false, 'HTTP')) {
            $client = $client->withRateLimit(new RateLimiter(
                ResolvedConfig::int($rateLimit, 'max_requests', 60, 'HTTP'),
                ResolvedConfig::int($rateLimit, 'per_seconds', 60, 'HTTP'),
                $this->clock,
            ));
        }

        $circuit = ResolvedConfig::section($config, 'circuit_breaker', 'HTTP');
        if (ResolvedConfig::bool($circuit, 'enabled', false, 'HTTP')) {
            $client = $client->withCircuitBreaker(new CircuitBreaker(
                ResolvedConfig::int($circuit, 'failure_threshold', 5, 'HTTP'),
                ResolvedConfig::int($circuit, 'cool_down_seconds', 30, 'HTTP'),
                $this->clock,
            ));
        }

        $idempotency = ResolvedConfig::section($config, 'idempotency', 'HTTP');
        if (ResolvedConfig::bool($idempotency, 'enabled', false, 'HTTP')) {
            $client = $client->withIdempotency(
                ResolvedConfig::string($idempotency, 'header', 'Idempotency-Key', 'HTTP', true),
            );
        }

        return $client;
    }

    public function withRunwire(
        RuntimeContext $runtime,
        ?RequestContext $request = null,
        ?CoroutineScope $scope = null,
    ): self {
        if ($this->runwireBinding !== null) {
            $this->runwireBinding->assertSameContext($runtime, $request, $scope);

            return $this;
        }

        $binding = new RunwireBinding($runtime, $request, $scope);
        $deadline = $binding->deadline();
        if ($deadline !== null && $this->operationDeadline !== null) {
            $deadline = $this->operationDeadline->earliest($deadline);
        } elseif ($deadline === null) {
            $deadline = $this->operationDeadline;
        }

        return new self(
            $this->events,
            $binding->cancellation($this->cancellation),
            $this->clock,
            $binding->sleeper($this->sleeper),
            $deadline,
            $binding,
        );
    }

    /**
     * @param array<string, mixed> $auth
     */
    private function applyAuth(HttpClient $client, array $auth): HttpClient
    {
        return match (ResolvedConfig::string($auth, 'driver', 'none', 'HTTP')) {
            'api_key', 'api_key_header', 'api-key-header', 'header' => $client->withApiKeyHeader(
                ResolvedConfig::string($auth, 'header', 'X-Api-Key', 'HTTP', true),
                ResolvedConfig::string($auth, 'value', '', 'HTTP', true),
            ),
            'api_key_query', 'api-key-query', 'query' => $client->withApiKeyQuery(
                ResolvedConfig::string($auth, 'query_key', 'api_key', 'HTTP', true),
                ResolvedConfig::string($auth, 'value', '', 'HTTP', true),
            ),
            'basic' => $client->withBasicAuth(
                ResolvedConfig::string($auth, 'username', '', 'HTTP', true),
                ResolvedConfig::string($auth, 'password', '', 'HTTP', true),
            ),
            'bearer' => $client->withBearerToken(ResolvedConfig::string($auth, 'token', '', 'HTTP', true)),
            'none', '' => $client,
            default => throw new InvalidArgumentException('Unsupported HTTP auth driver.'),
        };
    }
}
