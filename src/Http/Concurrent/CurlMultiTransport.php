<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Concurrent;

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpRedactor;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Internal\CurlHandleConfigurator;
use Infocyph\TalkingBytes\Http\Internal\CurlResultFactory;
use Infocyph\TalkingBytes\Http\Internal\RequestSecurityGuard;
use Infocyph\TalkingBytes\Http\Internal\ResponseBodyCollector;
use Infocyph\TalkingBytes\Http\Internal\ResponseHeaderCollector;
use InvalidArgumentException;

final readonly class CurlMultiTransport
{
    /**
     * @param array<int|string, HttpRequest> $requests
     */
    public function sendMany(array $requests, int $maxConcurrency = 10, bool $failFast = false): PoolResult
    {
        CommunicationEventBus::dispatch('http.pool.start', [
            'request_count' => count($requests),
            'max_concurrency' => $maxConcurrency,
            'fail_fast' => $failFast,
            'transport' => 'curl-multi',
        ]);

        $limit = new ConcurrencyLimit($maxConcurrency);
        $chunkSize = max(1, $limit->value);
        $results = [];
        $start = microtime(true);

        foreach (array_chunk($requests, $chunkSize, true) as $chunk) {
            $chunkResults = $this->sendChunk($chunk);
            foreach ($chunkResults as $key => $result) {
                $results[$key] = $result;
            }

            if ($failFast && $this->containsFailure($chunkResults)) {
                $pool = new PoolResult(
                    $results,
                    ['duration_ms' => (int) ((microtime(true) - $start) * 1000), 'fail_fast' => true],
                );
                CommunicationEventBus::dispatch('http.pool.finish', [
                    'request_count' => count($requests),
                    'successful_count' => $pool->successfulCount(),
                    'failed_count' => $pool->failedCount(),
                    'duration_ms' => $pool->metadata['duration_ms'] ?? null,
                    'fail_fast' => true,
                    'transport' => 'curl-multi',
                ]);

                return $pool;
            }
        }

        $pool = new PoolResult(
            $results,
            ['duration_ms' => (int) ((microtime(true) - $start) * 1000), 'fail_fast' => false],
        );
        CommunicationEventBus::dispatch('http.pool.finish', [
            'request_count' => count($requests),
            'successful_count' => $pool->successfulCount(),
            'failed_count' => $pool->failedCount(),
            'duration_ms' => $pool->metadata['duration_ms'] ?? null,
            'fail_fast' => false,
            'transport' => 'curl-multi',
        ]);

        return $pool;
    }

    private function cleanupUploadHandle(HttpRequest $request): void
    {
        $openedByConfigurator = $request->metadata['_upload_opened_by_configurator'] ?? false;
        $resource = $request->metadata['_upload_handle'] ?? null;

        if ($openedByConfigurator !== true || !is_resource($resource)) {
            return;
        }

        fclose($resource);
    }

    /**
     * @param array<int|string, CommunicationResult> $results
     */
    private function containsFailure(array $results): bool
    {
        return array_any($results, static fn(CommunicationResult $result): bool => !$result->successful);
    }

    private function dispatchRequestResultEvent(HttpRequest $request, CommunicationResult $result): void
    {
        CommunicationEventBus::dispatch($result->successful ? 'http.request.finish' : 'http.request.failed', [
            'method' => $request->method->value,
            'url' => HttpRedactor::redactUrl($request->buildUrl()),
            'status' => $result->statusCode,
            'error' => $result->error,
            'transport' => 'curl-multi',
        ]);
    }

    /**
     * @param array{handle: \CurlHandle, request: HttpRequest, headerCollector: ResponseHeaderCollector, bodyCollector: ResponseBodyCollector} $context
     */
    private function finalizeContext(array $context): CommunicationResult
    {
        $rawBody = curl_multi_getcontent($context['handle']);
        $info = curl_getinfo($context['handle']);
        $streamFinalizeError = $context['bodyCollector']->finalize();
        $body = is_string($rawBody) ? $rawBody : $context['bodyCollector']->responseBody();

        $this->cleanupUploadHandle($context['request']);

        if ($streamFinalizeError !== null) {
            return CommunicationResult::failure(
                $streamFinalizeError,
                metadata: ['transport' => 'curl-multi', 'curl' => is_array($info) ? $info : []],
            );
        }

        $result = CurlResultFactory::fromExecution(
            $context['request'],
            'curl-multi',
            $body,
            curl_errno($context['handle']),
            $context['bodyCollector']->error() ?? curl_error($context['handle']),
            $info,
            $context['headerCollector']->headers(),
        );

        $effectiveUrl = $info !== false ? $info['url'] : '';
        if ($effectiveUrl === '') {
            return $result;
        }

        try {
            RequestSecurityGuard::assertAllowed($context['request'], $effectiveUrl);
        } catch (InvalidArgumentException $exception) {
            return CommunicationResult::failure(
                $exception->getMessage(),
                $result->statusCode,
                $result->response,
                $result->metadata,
            );
        }

        return $result;
    }

    /**
     * @param array<int|string, CommunicationResult> $results
     * @return array{handle: \CurlHandle, request: HttpRequest, headerCollector: ResponseHeaderCollector, bodyCollector: ResponseBodyCollector}|null
     */
    private function prepareContext(
        \CurlMultiHandle $multiHandle,
        CurlHandleConfigurator $configurator,
        HttpRequest $request,
        array &$results,
        int|string $index,
    ): ?array {
        $prepared = $request->applyAuthenticators();

        try {
            RequestSecurityGuard::assertAllowed($prepared);
        } catch (InvalidArgumentException $exception) {
            $results[$index] = CommunicationResult::failure($exception->getMessage(), metadata: ['transport' => 'curl-multi']);

            return null;
        }

        $handle = curl_init();
        if ($handle === false) {
            $results[$index] = CommunicationResult::failure('Unable to initialize cURL handle.');

            return null;
        }

        $collector = new ResponseHeaderCollector();
        $bodyCollector = null;

        try {
            $bodyCollector = new ResponseBodyCollector($prepared);
            $prepared = $configurator->configure($handle, $prepared, $collector, $bodyCollector);
        } catch (InvalidArgumentException $exception) {
            $bodyCollector?->finalize();
            curl_close($handle);
            $results[$index] = CommunicationResult::failure($exception->getMessage());

            return null;
        }

        CommunicationEventBus::dispatch('http.request.start', [
            'method' => $prepared->method->value,
            'url' => HttpRedactor::redactUrl($prepared->buildUrl()),
            'headers' => HttpRedactor::redactHeaders($prepared->headers->all()),
            'transport' => 'curl-multi',
        ]);

        curl_multi_add_handle($multiHandle, $handle);

        return [
            'handle' => $handle,
            'request' => $prepared,
            'headerCollector' => $collector,
            'bodyCollector' => $bodyCollector,
        ];
    }

    private function runMultiLoop(\CurlMultiHandle $multiHandle): void
    {
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($status !== CURLM_OK) {
                break;
            }

            curl_multi_select($multiHandle, 1.0);
        } while ($running > 0);
    }

    /**
     * @param array<int|string, HttpRequest> $requests
     * @return array<int|string, CommunicationResult>
     */
    private function sendChunk(array $requests): array
    {
        $multiHandle = curl_multi_init();

        /**
         * @var array<int|string, array{handle: \CurlHandle, request: HttpRequest, headerCollector: ResponseHeaderCollector, bodyCollector: ResponseBodyCollector}> $contexts
         */
        $contexts = [];

        /** @var array<int|string, CommunicationResult> $results */
        $results = [];

        $configurator = new CurlHandleConfigurator();

        foreach ($requests as $index => $request) {
            $context = $this->prepareContext($multiHandle, $configurator, $request, $results, $index);
            if ($context === null) {
                continue;
            }

            $contexts[$index] = $context;
        }

        $this->runMultiLoop($multiHandle);

        foreach ($contexts as $index => $context) {
            $results[$index] = $this->finalizeContext($context);
            $this->dispatchRequestResultEvent($context['request'], $results[$index]);
        }

        foreach ($contexts as $context) {
            curl_multi_remove_handle($multiHandle, $context['handle']);
        }

        curl_multi_close($multiHandle);

        $orderedResults = [];
        foreach (array_keys($requests) as $key) {
            if (array_key_exists($key, $results)) {
                $orderedResults[$key] = $results[$key];
            }
        }

        return $orderedResults;
    }
}
