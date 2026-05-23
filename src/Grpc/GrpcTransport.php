<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

final readonly class GrpcTransport implements TransportInterface
{
    /**
     * @var Closure(GrpcRequest): GrpcResponse
     */
    private Closure $caller;

    /**
     * @param callable(GrpcRequest): GrpcResponse $caller
     */
    public function __construct(callable $caller)
    {
        $this->caller = Closure::fromCallable($caller);
    }

    public function send(CommunicationRequest $request): CommunicationResult
    {
        if (!$request->payload instanceof GrpcRequest) {
            return CommunicationResult::failure('GrpcTransport expects GrpcRequest payload.');
        }

        $response = ($this->caller)($request->payload);

        if (!$response->isOk()) {
            return CommunicationResult::failure(
                sprintf('gRPC call failed with status %d.', $response->status->value),
                $response->status->value,
                $response,
                ['transport' => 'grpc'],
            );
        }

        return CommunicationResult::success($response->status->value, $response, ['transport' => 'grpc']);
    }
}
