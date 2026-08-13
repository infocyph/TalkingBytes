<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Signing;

use InvalidArgumentException;

final class HmacWebhookSigner implements WebhookSigner
{
    public function sign(string $payload, int $timestamp, #[\SensitiveParameter] string $secret): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Webhook signing secret must not be empty.');
        }

        if ($timestamp < 1) {
            throw new InvalidArgumentException('Webhook signing timestamp must be greater than zero.');
        }

        return hash_hmac('sha256', sprintf('%d.%s', $timestamp, $payload), $secret);
    }
}
