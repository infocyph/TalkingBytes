<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Transport;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Internal\CurlHandleConfigurator;
use Infocyph\TalkingBytes\Http\Internal\CurlResultFactory;
use Infocyph\TalkingBytes\Http\Internal\RedirectResolver;
use Infocyph\TalkingBytes\Http\Internal\RequestSecurityGuard;
use Infocyph\TalkingBytes\Http\Internal\ResponseBodyCollector;
use Infocyph\TalkingBytes\Http\Internal\ResponseHeaderCollector;
use Infocyph\TalkingBytes\Http\Internal\UploadHandleManager;
use Infocyph\TalkingBytes\Http\Support\HttpRedactor;
use InvalidArgumentException;
use Throwable;

final readonly class CurlTransport implements HttpTransport
{
    private EventDispatcher $events;

    public function __construct(?EventDispatcher $events = null)
    {
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
    }

    public function send(HttpRequest $request): CommunicationResult
    {
        $request = $request->prepareForTransport();
        $startedAt = microtime(true);
        $this->dispatchStartEvent($request, $request->buildUrl());
        $current = $request;
        $visited = [];
        $redirects = 0;

        while (true) {
            $url = $current->buildUrl();
            $loopKey = preg_replace('/#.*$/', '', $url) ?? $url;
            if (isset($visited[$loopKey])) {
                $result = CommunicationResult::failure('HTTP redirect loop detected.', metadata: ['transport' => 'curl']);

                break;
            }
            $visited[$loopKey] = true;

            $result = $this->executeSingle($current, $url);
            $response = $result->response;
            if (!$current->options->followRedirects || !$response instanceof \Infocyph\TalkingBytes\Http\HttpResponse) {
                break;
            }

            $status = $response->statusCode;
            if (!is_int($status) || !in_array($status, [301, 302, 303, 307, 308], true)) {
                break;
            }

            $location = $response->header('Location');
            if (is_array($location)) {
                $location = $location[0] ?? null;
            }
            if (!is_string($location) || $location === '') {
                break;
            }

            if ($redirects >= $current->options->maxRedirects) {
                $result = CommunicationResult::failure(
                    sprintf('HTTP redirect limit exceeded (%d).', $current->options->maxRedirects),
                    $status,
                    $response,
                    ['transport' => 'curl'],
                );

                break;
            }

            try {
                $nextUrl = RedirectResolver::resolve($url, $location);
                $this->assertRedirectScheme($current, $url, $nextUrl);
                $sameOrigin = $this->sameOrigin($url, $nextUrl);
                $current = $current->redirectedTo($nextUrl, $status, $sameOrigin)->prepareForTransport();
                RequestSecurityGuard::assertAllowed($current, $nextUrl);
            } catch (InvalidArgumentException $exception) {
                $result = CommunicationResult::failure(
                    $exception->getMessage(),
                    $status,
                    $response,
                    ['transport' => 'curl'],
                );

                break;
            }

            $redirects++;
        }

        $this->dispatchResultEvents($current, $result, $startedAt);

        return $result;
    }

    private function assertRedirectScheme(HttpRequest $request, string $from, string $to): void
    {
        $fromScheme = strtolower((string) parse_url($from, PHP_URL_SCHEME));
        $toScheme = strtolower((string) parse_url($to, PHP_URL_SCHEME));
        if ($fromScheme === 'https' && $toScheme === 'http'
            && ($request->metadata['allow_https_downgrade_redirect'] ?? false) !== true
        ) {
            throw new InvalidArgumentException('HTTPS to HTTP redirects are blocked by default.');
        }
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
    private function configureHandle(
        \CurlHandle $handle,
        HttpRequest $request,
        ?string $pinnedResolution,
    ): array|CommunicationResult {
        $headerCollector = new ResponseHeaderCollector();
        $bodyCollector = null;

        try {
            $bodyCollector = new ResponseBodyCollector($request, $headerCollector);
            $configurator = new CurlHandleConfigurator();
            $request = $configurator->configure($handle, $request, $headerCollector, $bodyCollector, $pinnedResolution);
        } catch (InvalidArgumentException $exception) {
            $bodyCollector?->finalize();

            return CommunicationResult::failure($exception->getMessage(), metadata: ['transport' => 'curl']);
        }

        return [
            'request' => $request,
            'headerCollector' => $headerCollector,
            'bodyCollector' => $bodyCollector,
        ];
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
            $this->events->dispatch('http.request.finish', $payload);

            return;
        }

        $this->events->dispatch('http.request.failed', [
            ...$payload,
            'error' => $result->error,
        ]);
    }

    private function dispatchStartEvent(HttpRequest $request, string $url): void
    {
        $this->events->dispatch('http.request.start', [
            'method' => $request->method->value,
            'url' => HttpRedactor::redactUrl($url),
            'headers' => HttpRedactor::redactHeaders($request->headers->all()),
            'transport' => 'curl',
        ]);
    }

    private function executeSingle(HttpRequest $resolvedRequest, string $url): CommunicationResult
    {

        try {
            $pinnedResolution = RequestSecurityGuard::pinnedResolution($resolvedRequest, $url);
        } catch (InvalidArgumentException $exception) {
            return CommunicationResult::failure($exception->getMessage(), metadata: ['transport' => 'curl']);
        }
        $handle = curl_init();

        if ($handle === false) {
            return CommunicationResult::failure('Unable to initialize cURL handle.', metadata: ['transport' => 'curl']);
        }

        $configured = $this->configureHandle($handle, $resolvedRequest, $pinnedResolution);
        if ($configured instanceof CommunicationResult) {
            unset($handle);

            return $configured;
        }

        $resolvedRequest = $configured['request'];
        $headerCollector = $configured['headerCollector'];
        $bodyCollector = $configured['bodyCollector'];

        $rawBody = false;
        $errno = 0;
        $error = '';
        $info = false;
        $executionError = null;

        try {
            $rawBody = curl_exec($handle);
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            $info = curl_getinfo($handle);
        } catch (Throwable $throwable) {
            $executionError = $throwable;
        } finally {
            $streamFinalizeError = $bodyCollector->finalize();
            $this->cleanupUploadHandle($resolvedRequest);
            unset($handle);
        }

        if ($executionError !== null) {
            return CommunicationResult::failure(
                'cURL request failed: ' . $executionError->getMessage(),
                metadata: ['transport' => 'curl', 'exception' => $executionError::class],
            );
        }

        return $this->buildExecutionResult(
            $resolvedRequest,
            $rawBody,
            $bodyCollector,
            $streamFinalizeError,
            $errno,
            $error,
            $info,
            $headerCollector,
        );
    }

    private function sameOrigin(string $first, string $second): bool
    {
        $firstScheme = strtolower((string) parse_url($first, PHP_URL_SCHEME));
        $secondScheme = strtolower((string) parse_url($second, PHP_URL_SCHEME));
        $firstHost = strtolower((string) parse_url($first, PHP_URL_HOST));
        $secondHost = strtolower((string) parse_url($second, PHP_URL_HOST));
        $firstPort = parse_url($first, PHP_URL_PORT) ?: ($firstScheme === 'https' ? 443 : 80);
        $secondPort = parse_url($second, PHP_URL_PORT) ?: ($secondScheme === 'https' ? 443 : 80);

        return $firstScheme === $secondScheme && $firstHost === $secondHost && $firstPort === $secondPort;
    }
}
