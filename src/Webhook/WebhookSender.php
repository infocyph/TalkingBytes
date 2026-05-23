<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use LogicException;

final readonly class WebhookSender
{
    private function __construct(
        private HttpClient $httpClient,
        private ?string $signingSecret = null,
    ) {}

    public static function usingHttp(HttpClient $httpClient): self
    {
        return new self($httpClient);
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

        $request = HttpRequest::post($webhook->url)
            ->raw($payload, 'application/json')
            ->header(WebhookHeaders::EVENT, $webhook->event)
            ->header(WebhookHeaders::DELIVERY, $webhook->deliveryId)
            ->header(WebhookHeaders::TIMESTAMP, (string) $timestamp)
            ->headers($webhook->headers);

        if ($this->signingSecret !== null) {
            $signature = new WebhookSignature($this->signingSecret)->buildHeader($payload, $timestamp);
            $request = $request->header(WebhookHeaders::SIGNATURE, $signature);
        }

        $result = $this->httpClient->send($request);

        return new WebhookDelivery($webhook, $result);
    }

    public function signingSecret(string $secret): self
    {
        return new self($this->httpClient, $secret);
    }
}
