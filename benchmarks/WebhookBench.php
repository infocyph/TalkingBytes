<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Benchmarks;

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Webhook\Model\WebhookSignature;
use Infocyph\TalkingBytes\Webhook\Replay\InMemoryWebhookReplayStore;
use Infocyph\TalkingBytes\Webhook\Signing\WebhookSignatureParser;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;

#[BeforeMethods('setUp')]
final class WebhookBench
{
    private WebhookSignatureParser $parser;

    private string $payload;

    private InMemoryWebhookReplayStore $replayStore;

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
        $this->verifier = new WebhookVerifier(['current-secret', 'secret'], 300);
        $this->parser = new WebhookSignatureParser();
        $this->replayStore = new InMemoryWebhookReplayStore(10_000);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchParseSignature(): void
    {
        $this->parser->parse($this->signatureHeader);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchReplayClaim(): void
    {
        $this->replayStore->claim('bench', bin2hex(random_bytes(8)), 60);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchVerifyWebhook(): void
    {
        $this->verifier->verify($this->payload, $this->signatureHeader, $this->timestamp);
    }
}
