<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Signing;

final readonly class SignatureVerifier
{
    public function __construct(private RequestSigner $signer) {}

    public function verify(string $payload, string $signature): bool
    {
        return hash_equals($this->signer->sign($payload), $signature);
    }
}
