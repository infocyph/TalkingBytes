<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Http\HttpRequest;

final readonly class TimeoutMiddleware implements MiddlewareInterface
{
    public function __construct(private int $timeoutSeconds) {}

    public function handle(CommunicationRequest $request, Closure $next): CommunicationResult
    {
        $options = $request->options;
        $options['timeout'] = $this->timeoutSeconds;

        $payload = $request->payload;

        if ($payload instanceof HttpRequest) {
            $payload = $payload->timeout($this->timeoutSeconds);
        }

        if ($payload instanceof GrpcRequest) {
            $payload = $payload->withDeadlineSeconds((float) $this->timeoutSeconds);
        }

        return $next(new CommunicationRequest(
            $request->transport,
            $payload,
            $request->headers,
            $options,
            $request->metadata,
        ));
    }
}
