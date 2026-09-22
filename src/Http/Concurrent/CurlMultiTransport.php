<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Concurrent;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
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
    private Clock $clock;

    private EventDispatcher $events;

    private Sleeper $sleeper;

    public function __construct(
        ?Sleeper $sleeper = null,
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
    ) {
        $this->sleeper = $sleeper ?? Sleeper::system();
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
    }

    /**
     * @param array<int|string, HttpRequest> $requests
     */
    public function sendMany(
        array $requests,
        int $maxConcurrency = 10,
        bool $stopOnFailure = false,
        ?CancellationSignal $cancellation = null,
    ): PoolResult {
        $this->events->dispatch('http.pool.start', [
            'request_count' => count($requests),
            'max_concurrency' => $maxConcurrency,
            'stop_on_failure' => $stopOnFailure,
            'transport' => 'curl-multi',
        ]);

        $limit = new ConcurrencyLimit($maxConcurrency);
        $startedAt = $this->clock->monotonic();
        $keys = array_keys($requests);

        /** @var array<int|string, CommunicationResult> $results */
        $results = [];

        if ($keys === []) {
            return $this->finishPool($requests, $results, $startedAt, false, false);
        }

        $multiHandle = curl_multi_init();
        $configurator = new CurlHandleConfigurator();

        /**
         * @var array<int, array{key:int|string, handle:\CurlHandle, request:HttpRequest, headerCollector:ResponseHeaderCollector, bodyCollector:ResponseBodyCollector}> $contexts
         */
        $contexts = [];

        $nextIndex = 0;
        $stoppedScheduling = false;
        $cancelled = false;

        try {
            while (true) {
                $scheduled = [
                    'next_index' => $nextIndex,
                    'stopped' => false,
                    'cancelled' => false,
                ];

                if (!$stoppedScheduling) {
                    $scheduled = $this->scheduleAvailable(
                        $multiHandle,
                        $configurator,
                        $requests,
                        $keys,
                        $nextIndex,
                        $limit->value,
                        $results,
                        $contexts,
                        $stopOnFailure,
                        $cancellation,
                    );
                    $nextIndex = $scheduled['next_index'];
                    $stoppedScheduling = $scheduled['stopped'];
                }

                if ($scheduled['cancelled']) {
                    $cancelled = true;
                    $stoppedScheduling = true;
                    $this->cancelOutstanding($multiHandle, $keys, $nextIndex, $contexts, $results);

                    break;
                }

                if ($contexts === []) {
                    break;
                }

                $execution = $this->executeMulti($multiHandle);
                if ($execution['error'] !== null) {
                    $stoppedScheduling = true;
                    $this->failOutstanding(
                        $multiHandle,
                        $keys,
                        $nextIndex,
                        $contexts,
                        $results,
                        $execution['error'],
                    );

                    break;
                }

                $completed = $this->collectCompleted(
                    $multiHandle,
                    $contexts,
                    $results,
                    $stopOnFailure,
                );
                $stoppedScheduling = $stoppedScheduling || $completed['failure_observed'];

                if ($cancellation?->isRequested() === true) {
                    $cancelled = true;
                    $stoppedScheduling = true;
                    $this->cancelOutstanding($multiHandle, $keys, $nextIndex, $contexts, $results);

                    break;
                }

                if ($contexts !== [] && $completed['count'] === 0 && $execution['running'] > 0) {
                    $this->waitForActivity($multiHandle, $cancellation);
                }
            }
        } finally {
            foreach ($contexts as $context) {
                $this->abortContext($multiHandle, $context);
            }

            curl_multi_close($multiHandle);
        }

        return $this->finishPool(
            $requests,
            $this->orderResults($keys, $results),
            $startedAt,
            $stoppedScheduling,
            $cancelled,
        );
    }

    private static function cancelledResult(bool $started): CommunicationResult
    {
        return CommunicationResult::failure(
            'HTTP concurrent operation cancelled.',
            metadata: [
                'transport' => 'curl-multi',
                'cancelled' => true,
                'started' => $started,
            ],
        );
    }

    /**
     * @param array{key:int|string, handle:\CurlHandle, request:HttpRequest, headerCollector:ResponseHeaderCollector, bodyCollector:ResponseBodyCollector} $context
     */
    private function abortContext(\CurlMultiHandle $multiHandle, array $context): void
    {
        $context['bodyCollector']->abort();
        UploadHandleManager::cleanup($context['request']);
        curl_multi_remove_handle($multiHandle, $context['handle']);
    }

    /**
     * @param list<int|string> $keys
     * @param array<int, array{key:int|string, handle:\CurlHandle, request:HttpRequest, headerCollector:ResponseHeaderCollector, bodyCollector:ResponseBodyCollector}> $contexts
     * @param array<int|string, CommunicationResult> $results
     */
    private function cancelOutstanding(
        \CurlMultiHandle $multiHandle,
        array $keys,
        int $nextIndex,
        array &$contexts,
        array &$results,
    ): void {
        foreach ($contexts as $handleId => $context) {
            $result = self::cancelledResult(started: true);
            $results[$context['key']] = $result;
            $this->dispatchRequestResultEvent($context['request'], $result);
            $this->abortContext($multiHandle, $context);
            unset($contexts[$handleId]);
        }

        $total = count($keys);
        for ($index = $nextIndex; $index < $total; $index++) {
            $key = $keys[$index];
            if (!array_key_exists($key, $results)) {
                $results[$key] = self::cancelledResult(started: false);
            }
        }
    }

    /**
     * @param array<int, array{key:int|string, handle:\CurlHandle, request:HttpRequest, headerCollector:ResponseHeaderCollector, bodyCollector:ResponseBodyCollector}> $contexts
     * @param array<int|string, CommunicationResult> $results
     * @return array{count:int, failure_observed:bool}
     */
    private function collectCompleted(
        \CurlMultiHandle $multiHandle,
        array &$contexts,
        array &$results,
        bool $stopOnFailure,
    ): array {
        $count = 0;
        $failureObserved = false;

        while (($message = curl_multi_info_read($multiHandle)) !== false) {
            $handle = $message['handle'] ?? null;
            if (!$handle instanceof \CurlHandle) {
                continue;
            }

            $handleId = spl_object_id($handle);
            $context = $contexts[$handleId] ?? null;
            if ($context === null) {
                continue;
            }

            $result = $this->finalizeContext($context);
            $results[$context['key']] = $result;
            $this->dispatchRequestResultEvent($context['request'], $result);
            $this->releaseContext($multiHandle, $context);
            unset($contexts[$handleId]);

            $count++;
            if ($stopOnFailure && !$result->successful) {
                $failureObserved = true;
            }
        }

        return ['count' => $count, 'failure_observed' => $failureObserved];
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
     * @return array{error:?string, running:int}
     */
    private function executeMulti(\CurlMultiHandle $multiHandle): array
    {
        $status = curl_multi_exec($multiHandle, $running);
        if ($status !== CURLM_OK) {
            return [
                'error' => sprintf('cURL multi execution failed with status %d.', $status),
                'running' => 0,
            ];
        }

        return ['error' => null, 'running' => is_int($running) ? $running : 0];
    }

    /**
     * @param list<int|string> $keys
     * @param array<int, array{key:int|string, handle:\CurlHandle, request:HttpRequest, headerCollector:ResponseHeaderCollector, bodyCollector:ResponseBodyCollector}> $contexts
     * @param array<int|string, CommunicationResult> $results
     */
    private function failOutstanding(
        \CurlMultiHandle $multiHandle,
        array $keys,
        int $nextIndex,
        array &$contexts,
        array &$results,
        string $error,
    ): void {
        foreach ($contexts as $handleId => $context) {
            $result = CommunicationResult::failure($error, metadata: [
                'transport' => 'curl-multi',
                'scheduler_error' => true,
                'started' => true,
            ]);
            $results[$context['key']] = $result;
            $this->dispatchRequestResultEvent($context['request'], $result);
            $this->abortContext($multiHandle, $context);
            unset($contexts[$handleId]);
        }

        $total = count($keys);
        for ($index = $nextIndex; $index < $total; $index++) {
            $key = $keys[$index];
            if (!array_key_exists($key, $results)) {
                $results[$key] = CommunicationResult::failure($error, metadata: [
                    'transport' => 'curl-multi',
                    'scheduler_error' => true,
                    'started' => false,
                ]);
            }
        }
    }

    /**
     * @param array{key:int|string, handle:\CurlHandle, request:HttpRequest, headerCollector:ResponseHeaderCollector, bodyCollector:ResponseBodyCollector} $context
     */
    private function finalizeContext(array $context): CommunicationResult
    {
        $rawBody = curl_multi_getcontent($context['handle']);
        $info = curl_getinfo($context['handle']);
        $streamFinalizeError = $context['bodyCollector']->finalize();
        $body = is_string($rawBody) ? $rawBody : $context['bodyCollector']->responseBody();

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
     * @param array<int|string, HttpRequest> $requests
     * @param array<int|string, CommunicationResult> $results
     */
    private function finishPool(
        array $requests,
        array $results,
        float $startedAt,
        bool $stoppedScheduling,
        bool $cancelled,
    ): PoolResult {
        $pool = new PoolResult($results, [
            'duration_ms' => (int) (($this->clock->monotonic() - $startedAt) * 1000),
            'stopped_scheduling' => $stoppedScheduling,
            'cancelled' => $cancelled,
        ]);

        $this->events->dispatch('http.pool.finish', [
            'request_count' => count($requests),
            'successful_count' => $pool->successfulCount(),
            'failed_count' => $pool->failedCount(),
            'duration_ms' => $pool->metadata['duration_ms'],
            'stopped_scheduling' => $stoppedScheduling,
            'cancelled' => $cancelled,
            'transport' => 'curl-multi',
        ]);

        return $pool;
    }

    /**
     * @param list<int|string> $keys
     * @param array<int|string, CommunicationResult> $results
     * @return array<int|string, CommunicationResult>
     */
    private function orderResults(array $keys, array $results): array
    {
        $ordered = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }

    /**
     * @param array<int|string, CommunicationResult> $results
     * @return array{handle:\CurlHandle, request:HttpRequest, headerCollector:ResponseHeaderCollector, bodyCollector:ResponseBodyCollector}|null
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
            $results[$index] = CommunicationResult::failure(
                $exception->getMessage(),
                metadata: ['transport' => 'curl-multi'],
            );

            return null;
        }

        $handle = curl_init();
        if ($handle === false) {
            $results[$index] = CommunicationResult::failure(
                'Unable to initialize cURL handle.',
                metadata: ['transport' => 'curl-multi'],
            );

            return null;
        }

        $collector = new ResponseHeaderCollector();
        $bodyCollector = null;

        try {
            $bodyCollector = new ResponseBodyCollector($prepared, $collector);
            $prepared = $configurator->configure($handle, $prepared, $collector, $bodyCollector, $pinnedResolution);
        } catch (InvalidArgumentException $exception) {
            $bodyCollector?->abort();
            UploadHandleManager::cleanup($prepared);
            unset($handle);
            $results[$index] = CommunicationResult::failure(
                $exception->getMessage(),
                metadata: ['transport' => 'curl-multi'],
            );

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
            $bodyCollector->abort();
            UploadHandleManager::cleanup($prepared);
            unset($handle);
            $results[$index] = CommunicationResult::failure(
                sprintf('Unable to add request to cURL multi handle (%d).', $status),
                metadata: ['transport' => 'curl-multi'],
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

    /**
     * @param array{key:int|string, handle:\CurlHandle, request:HttpRequest, headerCollector:ResponseHeaderCollector, bodyCollector:ResponseBodyCollector} $context
     */
    private function releaseContext(\CurlMultiHandle $multiHandle, array $context): void
    {
        UploadHandleManager::cleanup($context['request']);
        curl_multi_remove_handle($multiHandle, $context['handle']);
    }

    /**
     * @param array<int|string, HttpRequest> $requests
     * @param list<int|string> $keys
     * @param array<int|string, CommunicationResult> $results
     * @param array<int, array{key:int|string, handle:\CurlHandle, request:HttpRequest, headerCollector:ResponseHeaderCollector, bodyCollector:ResponseBodyCollector}> $contexts
     * @return array{next_index:int, stopped:bool, cancelled:bool}
     */
    private function scheduleAvailable(
        \CurlMultiHandle $multiHandle,
        CurlHandleConfigurator $configurator,
        array $requests,
        array $keys,
        int $nextIndex,
        int $maxConcurrency,
        array &$results,
        array &$contexts,
        bool $stopOnFailure,
        ?CancellationSignal $cancellation,
    ): array {
        $total = count($keys);

        while ($nextIndex < $total && count($contexts) < $maxConcurrency) {
            if ($cancellation?->isRequested() === true) {
                return ['next_index' => $nextIndex, 'stopped' => true, 'cancelled' => true];
            }

            $key = $keys[$nextIndex];
            $nextIndex++;
            $context = $this->prepareContext(
                $multiHandle,
                $configurator,
                $requests[$key],
                $results,
                $key,
            );

            if ($context === null) {
                if ($stopOnFailure && isset($results[$key]) && !$results[$key]->successful) {
                    return ['next_index' => $nextIndex, 'stopped' => true, 'cancelled' => false];
                }

                continue;
            }

            $contexts[spl_object_id($context['handle'])] = [
                'key' => $key,
                ...$context,
            ];
        }

        return ['next_index' => $nextIndex, 'stopped' => false, 'cancelled' => false];
    }

    private function waitForActivity(
        \CurlMultiHandle $multiHandle,
        ?CancellationSignal $cancellation,
    ): void {
        $timeoutSeconds = $cancellation === null ? 1.0 : 0.05;
        if (curl_multi_select($multiHandle, $timeoutSeconds) !== -1) {
            return;
        }

        if ($cancellation === null) {
            $this->sleeper->milliseconds(10);

            return;
        }

        $this->sleeper->millisecondsInterruptibly(10, $cancellation, 10);
    }
}
