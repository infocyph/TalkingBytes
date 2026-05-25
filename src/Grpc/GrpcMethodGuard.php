<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use InvalidArgumentException;

final class GrpcMethodGuard
{
    public static function assertValid(string $method): void
    {
        $trimmed = trim($method);
        if ($trimmed === '') {
            throw new InvalidArgumentException('gRPC method must not be empty.');
        }

        if ($trimmed !== $method) {
            throw new InvalidArgumentException('gRPC method must not have surrounding whitespace.');
        }

        if (str_contains($trimmed, "\r") || str_contains($trimmed, "\n") || str_contains($trimmed, "\0")) {
            throw new InvalidArgumentException('gRPC method must not contain control characters.');
        }

        if (preg_match('#^/?[A-Za-z][A-Za-z0-9_.]*/[A-Za-z][A-Za-z0-9_]*$#', $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid gRPC method format "%s".', $method));
        }
    }
}
