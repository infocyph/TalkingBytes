<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Testing;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Webhook\Model\WebhookDelivery;
use Infocyph\TalkingBytes\Webhook\Model\WebhookDeliveryResult;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;

final class FakeWebhookSender
{
    /**
     * @var list<WebhookMessage>
     */
    private array $sent = [];

    public function assert(): AssertableWebhookSender
    {
        return new AssertableWebhookSender($this);
    }

    public function send(WebhookMessage $message): WebhookDelivery
    {
        $message->payloadForDelivery();
        $this->sent[] = $message;

        return new WebhookDelivery(
            message: $message,
            result: CommunicationResult::success(statusCode: 200),
            delivery: new WebhookDeliveryResult(
                deliveryId: $message->deliveryId,
                event: $message->event,
                url: $message->url ?? '',
                attempts: 1,
                delivered: true,
                statusCode: 200,
            ),
        );
    }

    /**
     * @return list<WebhookMessage>
     */
    public function sentMessages(): array
    {
        return $this->sent;
    }
}
