<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Core\Support\ResolvedConfig;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcStreamingInvoker;
use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Integration\Runwire\RunwireBinding;
use InvalidArgumentException;

final readonly class GrpcClientFactory
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
     * @param array<string, mixed> $config
     */
    private function applyResolvedConfig(GrpcClient $client, array $config): GrpcClient
    {
        if ($this->cancellation !== null) {
            $client = $client->withCancellation($this->cancellation);
        }

        if ($this->operationDeadline !== null) {
            $client = $client->withOperationDeadline($this->operationDeadline);
        }

        $retry = ResolvedConfig::section($config, 'retry', 'gRPC');
        if (!ResolvedConfig::bool($retry, 'enabled', false, 'gRPC')) {
            return $client;
        }

        $maxDelay = $retry['max_delay_ms'] ?? null;

        return $client->withGrpcRetry(
            GrpcRetryPolicy::standard(
                ResolvedConfig::int($retry, 'attempts', 3, 'gRPC'),
                ResolvedConfig::int($retry, 'base_delay_ms', 100, 'gRPC'),
                ResolvedConfig::nullableInt($maxDelay, 'max_delay_ms', 'gRPC'),
                ResolvedConfig::float($retry, 'jitter_ratio', 0.0, 'gRPC'),
            ),
            $this->cancellation,
            $this->sleeper,
        );
    }
}
