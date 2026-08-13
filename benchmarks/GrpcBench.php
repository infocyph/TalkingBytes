<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Benchmarks;

use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;

final class GrpcBench
{
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
}
