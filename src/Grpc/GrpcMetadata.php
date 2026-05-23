<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

final readonly class GrpcMetadata
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(public array $headers = []) {}
}
