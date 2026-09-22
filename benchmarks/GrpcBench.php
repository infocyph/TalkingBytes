<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Benchmarks;

use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\Testing\FakeGrpcInboundSource;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;

#[BeforeMethods('setUp')]
final class GrpcBench
{
    private GrpcClient $client;

    private GrpcInboundDispatcher $dispatcher;

    private GrpcClient $generatedClient;

    private GrpcInboundRequest $inboundRequest;

    private GrpcRequest $request;

    private GrpcClient $retryClient;

    public function setUp(): void
    {
        $caller = static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(
            GrpcStatus::Ok,
            $request->message,
        );

        $this->client = GrpcClient::using($caller);
        $this->retryClient = GrpcClient::using($caller)
            ->withGrpcRetry(GrpcRetryPolicy::standard(attempts: 2, baseDelayMs: 0));

        $stub = new class {
            public function GetOrder(mixed $message, array $metadata = [], array $options = []): object
            {
                unset($metadata, $options);

                $call = new class {
                    public mixed $message = null;

                    public function wait(): array
                    {
                        return [$this->message, (object) ['code' => GrpcStatus::Ok->value]];
                    }

                    public function getMetadata(): array
                    {
                        return [];
                    }

                    public function getTrailingMetadata(): array
                    {
                        return [];
                    }
                };
                $call->message = $message;

                return $call;
            }
        };

        $this->generatedClient = GrpcClient::usingGeneratedStub(
            $stub,
            ['/orders.v1.OrderService/GetOrder' => 'GetOrder'],
        );

        $this->dispatcher = new GrpcInboundDispatcher([
            '/orders.v1.OrderService/GetOrder' => static function (GrpcInboundRequest $request): GrpcInboundResponse {
                return GrpcInboundResponse::ok($request->message);
            },
        ]);
        $this->request = new GrpcRequest(
            '/orders.v1.OrderService/GetOrder',
            ['id' => 'order-1'],
            new GrpcMetadata(['x-request-id' => ['bench-1']]),
            1.5,
        );
        $this->inboundRequest = new GrpcInboundRequest(
            '/orders.v1.OrderService/GetOrder',
            ['id' => 'order-1'],
        );
    }

    #[Iterations(5)]
    #[Revs(500)]
    public function benchGeneratedStubInvocation(): void
    {
        $this->generatedClient->send($this->request);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchHostAcceptedExchangeBridge(): void
    {
        $source = new FakeGrpcInboundSource();
        $source->enqueue($this->inboundRequest);
        $this->dispatcher->serveOne($source);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchInboundDispatch(): void
    {
        $this->dispatcher->handle($this->inboundRequest);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchRequestAndMetadata(): void
    {
        new GrpcRequest(
            '/orders.v1.OrderService/GetOrder',
            ['id' => 'order-1'],
            new GrpcMetadata(['x-request-id' => ['bench-1']]),
            1.5,
        );
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchRetryMiddlewareSuccessPath(): void
    {
        $this->retryClient->send($this->request);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchUnaryDispatch(): void
    {
        $this->client->send($this->request);
    }
}
