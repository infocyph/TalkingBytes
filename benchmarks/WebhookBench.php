<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Benchmarks;

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

    private int $replayCounter = 0;

    private InMemoryWebhookReplayStore $replayStore;

    private WebhookSignature $signature;

    private string $signatureHeader;

    private int $timestamp;

    private WebhookVerifier $verifier;

    public function setUp(): void
    {
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
        $this->signature = new WebhookSignature('secret');
        $this->signatureHeader = $this->signature->buildHeader($this->payload, $this->timestamp, 'invoice.paid', 'delivery-bench');
        $this->verifier = new WebhookVerifier(['current-secret', 'secret'], 300);
        $this->parser = new WebhookSignatureParser();
        $this->replayStore = new InMemoryWebhookReplayStore(10_000);
        $this->replayStore->claim('bench', 'duplicate', 60);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchDuplicateRejection(): void
    {
        $this->replayStore->claim('bench', 'duplicate', 60);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchParseSignature(): void
    {
        $this->parser->parse($this->signatureHeader, version: 'v2');
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchReplayClaim(): void
    {
        $this->replayCounter++;
        $this->replayStore->claim('bench', 'delivery-' . $this->replayCounter, 60);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchSignWebhook(): void
    {
        $this->signature->buildHeader($this->payload, $this->timestamp, 'invoice.paid', 'delivery-bench');
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchVerificationAndReplayClaim(): void
    {
        $this->verifier->verifyResult($this->payload, $this->signatureHeader, now: $this->timestamp, event: 'invoice.paid', deliveryId: 'delivery-bench');
        $this->replayCounter++;
        $this->replayStore->claim('verify', 'delivery-' . $this->replayCounter, 60);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchVerifyWebhook(): void
    {
        $this->verifier->verifyResult($this->payload, $this->signatureHeader, now: $this->timestamp, event: 'invoice.paid', deliveryId: 'delivery-bench');
    }
}
