<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Concurrent;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Internal\CurlHandleConfigurator;
use Infocyph\TalkingBytes\Http\Internal\CurlResultFactory;
use Infocyph\TalkingBytes\Http\Internal\ResponseHeaderCollector;
use InvalidArgumentException;

final readonly class CurlMultiTransport
{
    /**
     * @param list<HttpRequest> $requests
     */
    public function sendMany(array $requests, int $maxConcurrency = 10, bool $failFast = false): PoolResult
    {
        $limit = new ConcurrencyLimit($maxConcurrency);
        $chunkSize = max(1, $limit->value);
        $results = [];
        $start = microtime(true);

        foreach (array_chunk($requests, $chunkSize) as $chunk) {
            $chunkResults = $this->sendChunk($chunk);
            $results = [...$results, ...$chunkResults];

            if ($failFast && $this->containsFailure($chunkResults)) {
                return new PoolResult(
                    $results,
                    ['duration_ms' => (int) ((microtime(true) - $start) * 1000), 'fail_fast' => true],
                );
            }
        }

        return new PoolResult(
            $results,
            ['duration_ms' => (int) ((microtime(true) - $start) * 1000), 'fail_fast' => false],
        );
    }

    /**
     * @param list<CommunicationResult> $results
     */
    private function containsFailure(array $results): bool
    {
        return array_any($results, static fn(CommunicationResult $result): bool => !$result->successful);
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
     * @param list<HttpRequest> $requests
     *
     * @return list<CommunicationResult>
     */
    private function sendChunk(array $requests): array
    {
        $multiHandle = curl_multi_init();

        /**
         * @var array<int, array{handle: \CurlHandle, request: HttpRequest, headerCollector: ResponseHeaderCollector}> $contexts
         */
        $contexts = [];

        /** @var array<int, CommunicationResult> $results */
        $results = [];

        $configurator = new CurlHandleConfigurator();

        foreach ($requests as $index => $request) {
            $prepared = $request->applyAuthenticators();
            $handle = curl_init();

            if ($handle === false) {
                $results[$index] = CommunicationResult::failure('Unable to initialize cURL handle.');

                continue;
            }

            $collector = new ResponseHeaderCollector();

            try {
                $prepared = $configurator->configure($handle, $prepared, $collector);
            } catch (InvalidArgumentException $exception) {
                unset($handle);
                $results[$index] = CommunicationResult::failure($exception->getMessage());

                continue;
            }

            curl_multi_add_handle($multiHandle, $handle);

            $contexts[$index] = [
                'handle' => $handle,
                'request' => $prepared,
                'headerCollector' => $collector,
            ];
        }

        $this->runMultiLoop($multiHandle);

        foreach ($contexts as $index => $context) {
            $rawBody = curl_multi_getcontent($context['handle']);
            $errno = curl_errno($context['handle']);
            $error = curl_error($context['handle']);
            $info = curl_getinfo($context['handle']);
            $body = is_string($rawBody) ? $rawBody : '';

            $results[$index] = CurlResultFactory::fromExecution(
                $context['request'],
                'curl-multi',
                $body,
                $errno,
                $error,
                $info,
                $context['headerCollector']->headers(),
            );
        }

        foreach ($contexts as $context) {
            curl_multi_remove_handle($multiHandle, $context['handle']);
        }

        curl_multi_close($multiHandle);

        ksort($results);

        return array_values($results);
    }
}
