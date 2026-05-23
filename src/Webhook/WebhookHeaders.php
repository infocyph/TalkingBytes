<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

final class WebhookHeaders
{
    public const string DELIVERY = 'X-TalkingBytes-Delivery';

    public const string EVENT = 'X-TalkingBytes-Event';

    public const string SIGNATURE = 'X-TalkingBytes-Signature';

    public const string TIMESTAMP = 'X-TalkingBytes-Timestamp';
}
