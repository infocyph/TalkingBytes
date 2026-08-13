<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Signing;

use InvalidArgumentException;

final readonly class HmacSha256Signer implements RequestSigner
{
    public function __construct(#[\SensitiveParameter] private string $secret)
    {
        if ($this->secret === '') {
            throw new InvalidArgumentException('HTTP signing key must not be empty.');
        }
    }

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
