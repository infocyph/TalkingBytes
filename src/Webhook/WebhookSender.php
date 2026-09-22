<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\ObservabilitySanitizer;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Support\HttpRedactor;
use Infocyph\TalkingBytes\Retry\RetryContext;
use Infocyph\TalkingBytes\Webhook\Model\WebhookDelivery;
use Infocyph\TalkingBytes\Webhook\Model\WebhookDeliveryResult;
use Infocyph\TalkingBytes\Webhook\Model\WebhookSignature;
use Infocyph\TalkingBytes\Webhook\Retry\WebhookRetryProfile;
use Infocyph\TalkingBytes\Webhook\Signing\HmacWebhookSigner;
use Infocyph\TalkingBytes\Webhook\Signing\WebhookSigner;
use Infocyph\TalkingBytes\Webhook\Support\WebhookHeaders;
use InvalidArgumentException;
use LogicException;

final readonly class WebhookSender
{
    private Clock $clock;

    private EventDispatcher $events;

    private WebhookSigner $resolvedSigner;

    private Sleeper $sleeper;

    public function __construct(
        private HttpClient $httpClient,
        #[\SensitiveParameter]
        private ?string $signingSecret = null,
        private ?WebhookSigner $signer = null,
        private ?WebhookRetryProfile $retryProfile = null,
        private int $maxPayloadBytes = 1_048_576,
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
        private ?CancellationSignal $cancellation = null,
    ) {
        if ($this->maxPayloadBytes < 1) {
            throw new InvalidArgumentException('Webhook max payload bytes must be greater than zero.');
        }
        if ($this->retryProfile !== null && $this->httpClient->hasRetryMiddleware()) {
            throw new InvalidArgumentException('Webhook and HTTP retry layers cannot both be enabled.');
        }

        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
        $this->sleeper = $sleeper ?? Sleeper::system();
        $this->resolvedSigner = $this->signer ?? new HmacWebhookSigner();
    }

    public static function usingHttp(HttpClient $httpClient): self
    {
        return new self($httpClient);
    }

    public static function usingHttpWithRetryProfile(
        HttpClient $httpClient,
        int $attempts = 3,
        int $baseDelayMs = 250,
        int $maxRetryAfterSeconds = 30,
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
        ?CancellationSignal $cancellation = null,
    ): self {
        $profile = WebhookRetryProfile::standard($attempts, $baseDelayMs, $maxRetryAfterSeconds);

        return new self(
            $httpClient,
            null,
            null,
            $profile,
            events: $events,
            clock: $clock,
            sleeper: $sleeper,
            cancellation: $cancellation,
        );
    }

    public function send(WebhookMessage $webhook): WebhookDelivery
    {
        $payload = $webhook->payloadForDelivery($this->maxPayloadBytes);
        $url = $webhook->deliveryUrl();

        $redactedUrl = HttpRedactor::redactUrl($url);
        $this->events->dispatch('webhook.send.start', [
            'event' => $webhook->event,
            'delivery_id' => $webhook->deliveryId,
            'url' => $redactedUrl,
            'attempt' => 1,
        ]);
        $startedAt = $this->clock->monotonic();
        $attempt = 1;
        $completedAttempts = 0;
        $cancelled = false;
        $retryPolicy = $this->retryProfile?->toHttpRetryPolicy();
        $result = null;

        while (true) {
            if ($this->cancellation?->isRequested() === true) {
                $cancelled = true;
                $result = $this->cancelledResult($result, $completedAttempts);

                break;
            }

            $timestamp = (int) floor($this->clock->timestamp());
            $request = HttpRequest::post($url)
                ->raw($payload, 'application/json')
                ->headers($webhook->headers)
                ->header(WebhookHeaders::EVENT, $webhook->event)
                ->header(WebhookHeaders::DELIVERY, $webhook->deliveryId)
                ->header(WebhookHeaders::TIMESTAMP, (string) $timestamp)
                ->header(WebhookHeaders::ATTEMPT, (string) $attempt)
                ->header(WebhookHeaders::CONTENT_TYPE, 'application/json');

            if ($this->signingSecret !== null) {
                $signature = $this->signature(WebhookSignature::deliveryPayload($payload, $webhook->event, $webhook->deliveryId), $timestamp);
                $request = $request->header(WebhookHeaders::SIGNATURE, sprintf('t=%d,v2=%s', $timestamp, $signature));
            }

            $result = $this->httpClient->send($request);
            $completedAttempts = $attempt;

            $decision = $retryPolicy?->decide(new RetryContext($attempt, $result));
            if ($decision === null || !$decision->retry) {
                break;
            }

            $this->events->dispatch('webhook.retry', [
                'event' => $webhook->event,
                'delivery_id' => $webhook->deliveryId,
                'url' => $redactedUrl,
                'attempt' => $attempt + 1,
                'status_code' => $result->statusCode,
                'failure_category' => ObservabilitySanitizer::resultContext($result)['failure_category'] ?? 'transport_error',
            ]);

            $delayMs = $decision->delayMs;
            if ($delayMs > 0) {
                if ($this->cancellation === null) {
                    $this->sleeper->milliseconds($delayMs);
                } elseif (!$this->sleeper->millisecondsInterruptibly($delayMs, $this->cancellation)) {
                    $cancelled = true;
                    $result = $this->cancelledResult($result, $completedAttempts);

                    break;
                }
            }

            $attempt++;
        }
        $delivery = new WebhookDeliveryResult(
            deliveryId: $webhook->deliveryId,
            event: $webhook->event,
            url: $url,
            attempts: $completedAttempts,
            delivered: $result->successful,
            statusCode: $result->statusCode,
            error: $result->error,
            metadata: [
                'duration_ms' => (int) (($this->clock->monotonic() - $startedAt) * 1000),
                'has_signature' => $this->signingSecret !== null,
                'cancelled' => $cancelled,
            ],
        );

        $this->events->dispatch(
            $result->successful ? 'webhook.send.finish' : 'webhook.send.failed',
            [
                'event' => $webhook->event,
                'delivery_id' => $webhook->deliveryId,
                'url' => $redactedUrl,
                'status_code' => $result->statusCode,
                'failure_category' => $result->successful
                    ? null
                    : (ObservabilitySanitizer::resultContext($result)['failure_category'] ?? 'transport_error'),
                'duration_ms' => $delivery->metadata['duration_ms'] ?? null,
                'attempt' => $completedAttempts,
                'cancelled' => $cancelled,
            ],
        );

        return new WebhookDelivery($webhook, $result, $delivery);
    }

    public function signingSecret(#[\SensitiveParameter] string $secret): self
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Webhook signing secret must not be empty.');
        }

        return new self($this->httpClient, $secret, $this->signer, $this->retryProfile, $this->maxPayloadBytes, $this->events, $this->clock, $this->sleeper, $this->cancellation);
    }

    public function withCancellation(?CancellationSignal $cancellation): self
    {
        return new self(
            $this->httpClient,
            $this->signingSecret,
            $this->signer,
            $this->retryProfile,
            $this->maxPayloadBytes,
            $this->events,
            $this->clock,
            $this->sleeper,
            $cancellation,
        );
    }

    public function withRetryProfile(
        int $attempts = 3,
        int $baseDelayMs = 250,
        int $maxRetryAfterSeconds = 30,
    ): self {
        $profile = WebhookRetryProfile::standard($attempts, $baseDelayMs, $maxRetryAfterSeconds);

        return new self($this->httpClient, $this->signingSecret, $this->signer, $profile, $this->maxPayloadBytes, $this->events, $this->clock, $this->sleeper, $this->cancellation);
    }

    public function withSecret(#[\SensitiveParameter] string $secret): self
    {
        return $this->signingSecret($secret);
    }

    public function withSigner(WebhookSigner $signer): self
    {
        return new self($this->httpClient, $this->signingSecret, $signer, $this->retryProfile, $this->maxPayloadBytes, $this->events, $this->clock, $this->sleeper, $this->cancellation);
    }

    private function cancelledResult(?CommunicationResult $previous, int $attempts): CommunicationResult
    {
        $metadata = $previous === null ? [] : $previous->metadata;

        return CommunicationResult::failure(
            'Webhook delivery cancelled.',
            $previous?->statusCode,
            $previous?->response,
            [
                ...$metadata,
                'cancelled' => true,
                'attempts' => $attempts,
            ],
        );
    }

    private function signature(string $payload, int $timestamp): string
    {
        if ($this->signingSecret === null) {
            throw new LogicException('Webhook signing secret is not configured.');
        }

        return $this->resolvedSigner->sign($payload, $timestamp, $this->signingSecret);
    }
}
