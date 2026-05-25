<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use InvalidArgumentException;

final class GrpcDeadline
{
    public static function secondsToMicros(float $seconds): int
    {
        if ($seconds <= 0.0) {
            throw new InvalidArgumentException('gRPC deadline seconds must be greater than zero.');
        }

        return (int) round($seconds * 1_000_000);
    }
}
