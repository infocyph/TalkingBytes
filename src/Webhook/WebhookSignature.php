<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Signing\HmacSha256Signer;

final readonly class WebhookSignature
{
    public function __construct(private string $secret) {}

    public function buildHeader(string $payload, int $timestamp): string
    {
        $signer = new HmacSha256Signer($this->secret);
        $signature = $signer->sign($timestamp . '.' . $payload);

        return sprintf('t=%d,v1=%s', $timestamp, $signature);
    }
}
