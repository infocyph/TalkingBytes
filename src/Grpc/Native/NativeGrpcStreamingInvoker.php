<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Native;

use Infocyph\TalkingBytes\Grpc\GrpcMetadata;

interface NativeGrpcStreamingInvoker
{
    /**
     * @param iterable<mixed> $messages
     * @param callable(mixed):void $onMessage
     */
    public function bidiStream(
        string $method,
        iterable $messages,
        GrpcMetadata $headers,
        callable $onMessage,
        ?float $deadlineSeconds = null,
    ): NativeGrpcResult;

    /**
     * @param iterable<mixed> $messages
     */
    public function clientStream(
        string $method,
        iterable $messages,
        GrpcMetadata $headers,
        ?float $deadlineSeconds = null,
    ): NativeGrpcResult;

    /**
     * @param callable(mixed):void $onMessage
     */
    public function serverStream(
        string $method,
        mixed $message,
        GrpcMetadata $headers,
        callable $onMessage,
        ?float $deadlineSeconds = null,
    ): NativeGrpcResult;
}
