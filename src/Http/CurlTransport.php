<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Internal\CurlHandleConfigurator;
use Infocyph\TalkingBytes\Http\Internal\CurlResultFactory;
use Infocyph\TalkingBytes\Http\Internal\RequestSecurityGuard;
use Infocyph\TalkingBytes\Http\Internal\ResponseBodyCollector;
use Infocyph\TalkingBytes\Http\Internal\ResponseHeaderCollector;
use Infocyph\TalkingBytes\Http\Internal\UploadHandleManager;
use InvalidArgumentException;

final class CurlTransport implements TransportInterface
{
    public function send(CommunicationRequest $request): CommunicationResult
    {
        if (!$request->payload instanceof HttpRequest) {
            return CommunicationResult::failure('CurlTransport expects HttpRequest payload.');
        }

        return $this->sendRequest($request->payload);
    }

    public function sendRequest(HttpRequest $request): CommunicationResult
    {
        $resolvedRequest = $request->applyAuthenticators();

        try {
            RequestSecurityGuard::assertAllowed($resolvedRequest);
        } catch (InvalidArgumentException $exception) {
            return CommunicationResult::failure($exception->getMessage(), metadata: ['transport' => 'curl']);
        }
        $url = $resolvedRequest->buildUrl();
        $startedAt = microtime(true);
        $this->dispatchStartEvent($resolvedRequest, $url);

        $handle = curl_init();

        if ($handle === false) {
            $this->dispatchInitializationFailure($resolvedRequest, $url, $startedAt, 'Unable to initialize cURL handle.');

            return CommunicationResult::failure('Unable to initialize cURL handle.');
        }

        $configured = $this->configureHandle($handle, $resolvedRequest, $url, $startedAt);
        if ($configured instanceof CommunicationResult) {
            curl_close($handle);

            return $configured;
        }

        $resolvedRequest = $configured['request'];
        $headerCollector = $configured['headerCollector'];
        $bodyCollector = $configured['bodyCollector'];

        $rawBody = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $info = curl_getinfo($handle);

        $streamFinalizeError = $bodyCollector->finalize();
        $this->cleanupUploadHandle($resolvedRequest);

        curl_close($handle);

        $result = $this->buildExecutionResult(
            $resolvedRequest,
            $rawBody,
            $bodyCollector,
            $streamFinalizeError,
            $errno,
            $error,
            $info,
            $headerCollector,
        );

        $effectiveUrl = $info !== false ? $info['url'] : '';
        if ($effectiveUrl !== '') {
            try {
                RequestSecurityGuard::assertAllowed($resolvedRequest, $effectiveUrl);
            } catch (InvalidArgumentException $exception) {
                $result = CommunicationResult::failure(
                    $exception->getMessage(),
                    $result->statusCode,
                    $result->response,
                    $result->metadata,
                );
            }
        }

        $this->dispatchResultEvents($resolvedRequest, $result, $startedAt);

        return $result;
    }

    /**
     * @param array<string, mixed>|false $info
     */
    private function buildExecutionResult(
        HttpRequest $request,
        mixed $rawBody,
        ResponseBodyCollector $bodyCollector,
        ?string $streamFinalizeError,
        int $errno,
        string $error,
        array|false $info,
        ResponseHeaderCollector $headerCollector,
    ): CommunicationResult {
        $body = is_string($rawBody) ? $rawBody : $bodyCollector->responseBody();

        if ($streamFinalizeError !== null) {
            return CommunicationResult::failure(
                $streamFinalizeError,
                metadata: ['curl' => is_array($info) ? $info : [], 'transport' => 'curl'],
            );
        }

        if (!is_string($rawBody) && $body === '') {
            return CommunicationResult::failure(
                sprintf('cURL request failed (%d): %s', $errno, $bodyCollector->error() ?? $error),
                metadata: ['curl' => is_array($info) ? $info : [], 'transport' => 'curl'],
            );
        }

        return CurlResultFactory::fromExecution(
            $request,
            'curl',
            $body,
            $errno,
            $bodyCollector->error() ?? $error,
            $info,
            $headerCollector->headers(),
        );
    }

    private function cleanupUploadHandle(HttpRequest $request): void
    {
        UploadHandleManager::cleanup($request);
    }

    /**
     * @return array{request: HttpRequest, headerCollector: ResponseHeaderCollector, bodyCollector: ResponseBodyCollector}|CommunicationResult
     */
    private function configureHandle(\CurlHandle $handle, HttpRequest $request, string $url, float $startedAt): array|CommunicationResult
    {
        $headerCollector = new ResponseHeaderCollector();
        $bodyCollector = null;

        try {
            $bodyCollector = new ResponseBodyCollector($request);
            $configurator = new CurlHandleConfigurator();
            $request = $configurator->configure($handle, $request, $headerCollector, $bodyCollector);
        } catch (InvalidArgumentException $exception) {
            $bodyCollector?->finalize();
            $this->dispatchInitializationFailure($request, $url, $startedAt, $exception->getMessage());

            return CommunicationResult::failure($exception->getMessage());
        }

        return [
            'request' => $request,
            'headerCollector' => $headerCollector,
            'bodyCollector' => $bodyCollector,
        ];
    }

    private function dispatchInitializationFailure(HttpRequest $request, string $url, float $startedAt, string $error): void
    {
        CommunicationEventBus::dispatch('http.request.failed', [
            'method' => $request->method->value,
            'url' => HttpRedactor::redactUrl($url),
            'error' => $error,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'transport' => 'curl',
        ]);
    }

    private function dispatchResultEvents(HttpRequest $request, CommunicationResult $result, float $startedAt): void
    {
        $payload = [
            'method' => $request->method->value,
            'url' => HttpRedactor::redactUrl($request->buildUrl()),
            'status' => $result->statusCode,
            'successful' => $result->successful,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'transport' => 'curl',
        ];

        if ($result->successful) {
            CommunicationEventBus::dispatch('http.request.finish', $payload);

            return;
        }

        CommunicationEventBus::dispatch('http.request.failed', [
            ...$payload,
            'error' => $result->error,
        ]);
    }

    private function dispatchStartEvent(HttpRequest $request, string $url): void
    {
        CommunicationEventBus::dispatch('http.request.start', [
            'method' => $request->method->value,
            'url' => HttpRedactor::redactUrl($url),
            'headers' => HttpRedactor::redactHeaders($request->headers->all()),
            'transport' => 'curl',
        ]);
    }
}
