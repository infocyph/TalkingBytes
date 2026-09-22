<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Benchmarks;

use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;

#[BeforeMethods('setUp')]
final class GrpcBench
{
    private GrpcClient $client;

    private GrpcInboundDispatcher $dispatcher;

    private GrpcInboundRequest $inboundRequest;

    private GrpcRequest $request;

    public function setUp(): void
    {
        $this->client = GrpcClient::using(
            static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(
                GrpcStatus::Ok,
                $request->message,
            ),
        );
        $this->dispatcher = new GrpcInboundDispatcher([
            '/orders.v1.OrderService/GetOrder' => static fn(GrpcInboundRequest $request): GrpcInboundResponse =>
                GrpcInboundResponse::ok($request->message),
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
    public function benchUnaryDispatch(): void
    {
        $this->client->send($this->request);
    }
}
