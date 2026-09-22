<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcStreamingInvoker;
use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use InvalidArgumentException;

final readonly class GrpcClientFactory
{
    public function __construct(
        private ?EventDispatcher $events = null,
        private ?CancellationSignal $cancellation = null,
        private ?Clock $clock = null,
    ) {}

    /**
     * @param callable(GrpcRequest):GrpcResponse $caller
     * @param array<string, mixed> $config
     */
    public function using(callable $caller, array $config = []): GrpcClient
    {
        return $this->applyResolvedConfig(
            GrpcClient::using($caller, $this->events, $this->clock),
            $config,
        );
    }

    /**
     * @param array<string, string> $methodMap
     * @param array<string, mixed> $config
     */
    public function usingGeneratedStub(
        object $stubClient,
        array $methodMap = [],
        array $config = [],
    ): GrpcClient {
        return $this->applyResolvedConfig(
            GrpcClient::usingGeneratedStub(
                $stubClient,
                $methodMap,
                $this->events,
                $this->cancellation,
                $this->clock,
            ),
            $config,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public function usingNative(
        NativeGrpcInvoker $invoker,
        ?NativeGrpcStreamingInvoker $streamingInvoker = null,
        array $config = [],
    ): GrpcClient {
        $client = $streamingInvoker instanceof NativeGrpcStreamingInvoker
            ? GrpcClient::usingNativeStreaming($invoker, $streamingInvoker, $this->events, $this->clock)
            : GrpcClient::usingNative($invoker, $this->events, $this->clock);

        return $this->applyResolvedConfig($client, $config);
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

        throw new InvalidArgumentException(sprintf('gRPC resolved configuration key "%s" must be a boolean.', $key));
    }

    /** @param array<string, mixed> $config */
    private static function float(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? $default;
        if (is_float($value) || is_int($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw new InvalidArgumentException(sprintf('gRPC resolved configuration key "%s" must be numeric.', $key));
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

        throw new InvalidArgumentException(sprintf('gRPC resolved configuration key "%s" must be an integer.', $key));
    }

    private static function nullableInt(mixed $value, string $key): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($parsed)) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException(sprintf('gRPC resolved configuration key "%s" must be an integer or null.', $key));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function section(array $config, string $key): array
    {
        $value = $config[$key] ?? [];
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('gRPC resolved configuration section "%s" must be an array.', $key));
        }

        $section = [];
        foreach ($value as $name => $item) {
            if (is_string($name)) {
                $section[$name] = $item;
            }
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applyResolvedConfig(GrpcClient $client, array $config): GrpcClient
    {
        $retry = self::section($config, 'retry');
        if (!self::bool($retry, 'enabled', false)) {
            return $client;
        }

        $maxDelay = $retry['max_delay_ms'] ?? null;

        return $client->withGrpcRetry(
            GrpcRetryPolicy::standard(
                self::int($retry, 'attempts', 3),
                self::int($retry, 'base_delay_ms', 100),
                self::nullableInt($maxDelay, 'max_delay_ms'),
                self::float($retry, 'jitter_ratio', 0.0),
            ),
            $this->cancellation,
        );
    }
}
