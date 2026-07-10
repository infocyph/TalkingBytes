<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Benchmarks;

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Webhook\Model\WebhookSignature;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;

#[BeforeMethods('setUp')]
final class WebhookBench
{
    private string $payload;

    private string $signatureHeader;

    private int $timestamp;

    private WebhookVerifier $verifier;

    public function setUp(): void
    {
        CommunicationEventBus::listen(null);

        $this->payload = json_encode([
            'id' => 1001,
            'event' => 'invoice.paid',
            'customer' => [
                'id' => 'cus_123',
                'email' => 'alice@example.com',
            ],
            'items' => [
                ['sku' => 'plan-pro', 'qty' => 1],
                ['sku' => 'addon-seat', 'qty' => 5],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->timestamp = 1_720_000_000;
        $this->signatureHeader = (new WebhookSignature('secret'))->buildHeader($this->payload, $this->timestamp);
        $this->verifier = new WebhookVerifier('secret', 300);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchVerifyWebhook(): void
    {
        $this->verifier->verify($this->payload, $this->signatureHeader, $this->timestamp);
    }
}
