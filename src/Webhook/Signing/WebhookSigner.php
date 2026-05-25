<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Signing;

interface WebhookSigner
{
    public function sign(string $payload, int $timestamp, string $secret): string;
}
