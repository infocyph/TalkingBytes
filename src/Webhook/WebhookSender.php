<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Support\HttpRedactor;
use Infocyph\TalkingBytes\Webhook\Model\WebhookDelivery;
use Infocyph\TalkingBytes\Webhook\Model\WebhookDeliveryResult;
use Infocyph\TalkingBytes\Webhook\Retry\WebhookRetryProfile;
use Infocyph\TalkingBytes\Webhook\Signing\HmacWebhookSigner;
use Infocyph\TalkingBytes\Webhook\Signing\WebhookSigner;
use Infocyph\TalkingBytes\Webhook\Support\WebhookHeaders;
use InvalidArgumentException;
use LogicException;

final readonly class WebhookSender
{
    public function __construct(
        private HttpClient $httpClient,
        private ?string $signingSecret = null,
        private ?WebhookSigner $signer = null,
        private ?WebhookRetryProfile $retryProfile = null,
    ) {}

    public static function usingHttp(HttpClient $httpClient): self
    {
        return new self($httpClient);
    }

    public static function usingHttpWithRetryProfile(
        HttpClient $httpClient,
        int $attempts = 3,
        int $baseDelayMs = 250,
        int $maxRetryAfterSeconds = 30,
    ): self {
        $profile = WebhookRetryProfile::standard($attempts, $baseDelayMs, $maxRetryAfterSeconds);

        return new self($httpClient, null, null, $profile);
    }

    public function send(WebhookMessage $webhook): WebhookDelivery
    {
        if ($webhook->url === null || $webhook->url === '') {
            throw new LogicException('Webhook URL is required before sending.');
        }

        $timestamp = time();
        $payload = is_string($webhook->payload)
            ? $webhook->payload
            : json_encode($webhook->payload, JSON_THROW_ON_ERROR);

        $redactedUrl = HttpRedactor::redactUrl($webhook->url);
        CommunicationEventBus::dispatch('webhook.send.start', [
            'event' => $webhook->event,
            'delivery_id' => $webhook->deliveryId,
            'url' => $redactedUrl,
            'attempt' => 1,
        ]);
        $startedAt = microtime(true);
        $attempt = 1;
        $retryPolicy = $this->retryProfile?->toHttpRetryPolicy();

        while (true) {
            $request = HttpRequest::post($webhook->url)
                ->raw($payload, 'application/json')
                ->headers($webhook->headers)
                ->header(WebhookHeaders::EVENT, $webhook->event)
                ->header(WebhookHeaders::DELIVERY, $webhook->deliveryId)
                ->header(WebhookHeaders::TIMESTAMP, (string) $timestamp)
                ->header(WebhookHeaders::ATTEMPT, (string) $attempt)
                ->header(WebhookHeaders::USER_AGENT, 'TalkingBytes/1.0')
                ->header(WebhookHeaders::CONTENT_TYPE, 'application/json');

            if ($this->signingSecret !== null) {
                $signature = $this->signature($payload, $timestamp);
                $request = $request->header(WebhookHeaders::SIGNATURE, sprintf('t=%d,v1=%s', $timestamp, $signature));
            }

            $result = $this->httpClient->send($request);

            if ($retryPolicy === null || !$retryPolicy->shouldRetry($attempt, $result)) {
                break;
            }

            CommunicationEventBus::dispatch('webhook.retry', [
                'event' => $webhook->event,
                'delivery_id' => $webhook->deliveryId,
                'url' => $redactedUrl,
                'attempt' => $attempt + 1,
                'status_code' => $result->statusCode,
                'error' => $result->error,
            ]);

            $delayMs = $retryPolicy->delayMs($attempt);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $attempt++;
        }
        $delivery = new WebhookDeliveryResult(
            deliveryId: $webhook->deliveryId,
            event: $webhook->event,
            url: $webhook->url,
            attempts: $attempt,
            delivered: $result->successful,
            statusCode: $result->statusCode,
            error: $result->error,
            metadata: [
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'has_signature' => $this->signingSecret !== null,
            ],
        );

        CommunicationEventBus::dispatch(
            $result->successful ? 'webhook.send.finish' : 'webhook.send.failed',
            [
                'event' => $webhook->event,
                'delivery_id' => $webhook->deliveryId,
                'url' => $redactedUrl,
                'status_code' => $result->statusCode,
                'error' => $result->error,
                'duration_ms' => $delivery->metadata['duration_ms'] ?? null,
                'attempt' => $attempt,
            ],
        );

        return new WebhookDelivery($webhook, $result, $delivery);
    }

    public function signingSecret(string $secret): self
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Webhook signing secret must not be empty.');
        }

        return new self($this->httpClient, $secret, $this->signer, $this->retryProfile);
    }

    public function withRetryProfile(
        int $attempts = 3,
        int $baseDelayMs = 250,
        int $maxRetryAfterSeconds = 30,
    ): self {
        $profile = WebhookRetryProfile::standard($attempts, $baseDelayMs, $maxRetryAfterSeconds);

        return new self($this->httpClient, $this->signingSecret, $this->signer, $profile);
    }

    public function withSecret(string $secret): self
    {
        return $this->signingSecret($secret);
    }

    public function withSigner(WebhookSigner $signer): self
    {
        return new self($this->httpClient, $this->signingSecret, $signer, $this->retryProfile);
    }

    private function signature(string $payload, int $timestamp): string
    {
        if ($this->signingSecret === null) {
            throw new LogicException('Webhook signing secret is not configured.');
        }

        return ($this->signer ?? new HmacWebhookSigner())
            ->sign($payload, $timestamp, $this->signingSecret);
    }
}
