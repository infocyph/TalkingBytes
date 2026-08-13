<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Sender;

use RuntimeException;

final class GrpcTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
