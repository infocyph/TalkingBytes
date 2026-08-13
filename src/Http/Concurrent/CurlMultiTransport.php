<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Concurrent;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Internal\CurlHandleConfigurator;
use Infocyph\TalkingBytes\Http\Internal\CurlResultFactory;
use Infocyph\TalkingBytes\Http\Internal\RequestSecurityGuard;
use Infocyph\TalkingBytes\Http\Internal\ResponseBodyCollector;
use Infocyph\TalkingBytes\Http\Internal\ResponseHeaderCollector;
use Infocyph\TalkingBytes\Http\Internal\UploadHandleManager;
use Infocyph\TalkingBytes\Http\Support\HttpRedactor;
use InvalidArgumentException;

final readonly class CurlMultiTransport
{
    private EventDispatcher $events;

    private Sleeper $sleeper;

    public function __construct(?Sleeper $sleeper = null, ?EventDispatcher $events = null)
    {
        $this->sleeper = $sleeper ?? Sleeper::system();
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
    }

    /**
     * @param array<int|string, HttpRequest> $requests
     */
    public function sendMany(array $requests, int $maxConcurrency = 10, bool $stopOnFailure = false): PoolResult
    {
        $this->events->dispatch('http.pool.start', [
            'request_count' => count($requests),
            'max_concurrency' => $maxConcurrency,
            'stop_on_failure' => $stopOnFailure,
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

            if ($stopOnFailure && $this->containsFailure($chunkResults)) {
                $pool = new PoolResult(
                    $results,
                    ['duration_ms' => (int) ((microtime(true) - $start) * 1000), 'stopped_scheduling' => true],
                );
                $this->events->dispatch('http.pool.finish', [
                    'request_count' => count($requests),
                    'successful_count' => $pool->successfulCount(),
                    'failed_count' => $pool->failedCount(),
                    'duration_ms' => $pool->metadata['duration_ms'] ?? null,
                    'stopped_scheduling' => true,
                    'transport' => 'curl-multi',
                ]);

                return $pool;
            }
        }

        $pool = new PoolResult(
            $results,
            ['duration_ms' => (int) ((microtime(true) - $start) * 1000), 'stopped_scheduling' => false],
        );
        $this->events->dispatch('http.pool.finish', [
            'request_count' => count($requests),
            'successful_count' => $pool->successfulCount(),
            'failed_count' => $pool->failedCount(),
            'duration_ms' => $pool->metadata['duration_ms'] ?? null,
            'stopped_scheduling' => false,
            'transport' => 'curl-multi',
        ]);

        return $pool;
    }

    private function cleanupUploadHandle(HttpRequest $request): void
    {
        UploadHandleManager::cleanup($request);
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
        $this->events->dispatch($result->successful ? 'http.request.finish' : 'http.request.failed', [
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
        $prepared = $request->prepareForTransport();

        try {
            RequestSecurityGuard::assertAllowed($prepared);
            if ($prepared->options->followRedirects) {
                throw new InvalidArgumentException(
                    'Concurrent HTTP requests do not follow redirects; use CurlTransport for manually validated redirect chains.',
                );
            }
            $pinnedResolution = RequestSecurityGuard::pinnedResolution($prepared, $prepared->buildUrl());
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
            $bodyCollector = new ResponseBodyCollector($prepared, $collector);
            $prepared = $configurator->configure($handle, $prepared, $collector, $bodyCollector, $pinnedResolution);
        } catch (InvalidArgumentException $exception) {
            $bodyCollector?->finalize();
            unset($handle);
            $results[$index] = CommunicationResult::failure($exception->getMessage());

            return null;
        }

        $this->events->dispatch('http.request.start', [
            'method' => $prepared->method->value,
            'url' => HttpRedactor::redactUrl($prepared->buildUrl()),
            'headers' => HttpRedactor::redactHeaders($prepared->headers->all()),
            'transport' => 'curl-multi',
        ]);

        $status = curl_multi_add_handle($multiHandle, $handle);
        if ($status !== CURLM_OK) {
            $bodyCollector->finalize();
            UploadHandleManager::cleanup($prepared);
            unset($handle);
            $results[$index] = CommunicationResult::failure(
                sprintf('Unable to add request to cURL multi handle (%d).', $status),
            );

            return null;
        }

        return [
            'handle' => $handle,
            'request' => $prepared,
            'headerCollector' => $collector,
            'bodyCollector' => $bodyCollector,
        ];
    }

    private function runMultiLoop(\CurlMultiHandle $multiHandle): ?string
    {
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($status !== CURLM_OK) {
                return sprintf('cURL multi execution failed with status %d.', $status);
            }

            if ($running > 0 && curl_multi_select($multiHandle, 1.0) === -1) {
                $this->sleeper->milliseconds(10);
            }
        } while ($running > 0);

        return null;
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

        try {
            foreach ($requests as $index => $request) {
                $context = $this->prepareContext($multiHandle, $configurator, $request, $results, $index);
                if ($context === null) {
                    continue;
                }

                $contexts[$index] = $context;
            }

            $multiError = $this->runMultiLoop($multiHandle);

            foreach ($contexts as $index => $context) {
                $results[$index] = $multiError === null
                    ? $this->finalizeContext($context)
                    : CommunicationResult::failure($multiError, metadata: ['transport' => 'curl-multi']);
                $this->dispatchRequestResultEvent($context['request'], $results[$index]);
            }
        } finally {
            foreach ($contexts as $context) {
                $context['bodyCollector']->finalize();
                UploadHandleManager::cleanup($context['request']);
                curl_multi_remove_handle($multiHandle, $context['handle']);
            }
            curl_multi_close($multiHandle);
        }

        $orderedResults = [];
        foreach (array_keys($requests) as $key) {
            if (array_key_exists($key, $results)) {
                $orderedResults[$key] = $results[$key];
            }
        }

        return $orderedResults;
    }
}
