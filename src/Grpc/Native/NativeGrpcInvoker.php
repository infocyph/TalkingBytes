<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Native;

use Infocyph\TalkingBytes\Grpc\GrpcMetadata;

interface NativeGrpcInvoker
{
    /**
     * Implementations should treat $deadlineSeconds as logical seconds from now.
     * Use GrpcDeadline::secondsToMicros() when native adapters expect microseconds.
     */
    public function invoke(
        string $method,
        mixed $message,
        GrpcMetadata $headers,
        ?float $deadlineSeconds = null,
    ): NativeGrpcResult;
}
