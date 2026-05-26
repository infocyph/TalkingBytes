<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Signing;

final readonly class HmacSha256Signer implements RequestSignerInterface
{
    public function __construct(private string $secret) {}

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
