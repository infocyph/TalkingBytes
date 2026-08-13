<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Model;

use Infocyph\TalkingBytes\Webhook\Signing\HmacWebhookSigner;
use InvalidArgumentException;

final readonly class WebhookSignature
{
    public function __construct(#[\SensitiveParameter] private string $secret)
    {
        if ($this->secret === '') {
            throw new InvalidArgumentException('Webhook signing secret must not be empty.');
        }
    }

    public function buildHeader(string $payload, int $timestamp): string
    {
        $signature = new HmacWebhookSigner()->sign($payload, $timestamp, $this->secret);

        return sprintf('t=%d,v1=%s', $timestamp, $signature);
    }
}
