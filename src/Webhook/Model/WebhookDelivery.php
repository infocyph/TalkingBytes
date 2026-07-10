<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Model;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;

final readonly class WebhookDelivery
{
    public function __construct(
        public WebhookMessage $message,
        public CommunicationResult $result,
        public ?WebhookDeliveryResult $delivery = null,
    ) {}
}
