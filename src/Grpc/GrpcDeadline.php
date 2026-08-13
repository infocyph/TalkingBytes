<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use InvalidArgumentException;

final class GrpcDeadline
{
    public static function secondsToMicros(float $seconds): int
    {
        if (!is_finite($seconds) || $seconds <= 0.0 || $seconds > PHP_INT_MAX / 1_000_000) {
            throw new InvalidArgumentException('gRPC deadline seconds must be finite, greater than zero, and representable in microseconds.');
        }

        return max(1, (int) ceil($seconds * 1_000_000));
    }
}
