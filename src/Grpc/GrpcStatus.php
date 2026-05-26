<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

enum GrpcStatus: int
{
    case Aborted = 10;

    case AlreadyExists = 6;

    case Cancelled = 1;

    case DataLoss = 15;

    case DeadlineExceeded = 4;

    case FailedPrecondition = 9;

    case Internal = 13;

    case InvalidArgument = 3;

    case NotFound = 5;

    case Ok = 0;

    case OutOfRange = 11;

    case PermissionDenied = 7;

    case ResourceExhausted = 8;

    case Unauthenticated = 16;

    case Unavailable = 14;

    case Unimplemented = 12;

    case Unknown = 2;

    public static function fromCode(int $code): self
    {
        return self::tryFrom($code) ?? self::Unknown;
    }
}
