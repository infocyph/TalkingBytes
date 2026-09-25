<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Transport;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\ObservabilitySanitizer;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\Cookie\CookieJar;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\HttpResponse;
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
    private Clock $clock;

    private EventDispatcher $events;

    public function __construct(?EventDispatcher $events = null, ?Clock $clock = null)
    {
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
    }

    public function send(HttpRequest $request): CommunicationResult
    {
        $request = $this->prepareCookieContext($request);
        $request = $this->applyCookiesForHop($request, $request->buildUrl())->prepareForTransport();
        $startedAt = $this->clock->monotonic();
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
            if ($response instanceof HttpResponse) {
                $this->storeResponseCookies($current, $response, $url);
            }

            if (!$current->options->followRedirects || !$response instanceof HttpResponse) {
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
                $current = $current->redirectedTo($nextUrl, $status, $sameOrigin);
                $current = $this->applyCookiesForHop($current, $nextUrl)->prepareForTransport();
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

        $result = CurlResultFactory::publishBufferedDownload($current, $result);
        $this->dispatchResultEvents($current, $result, $startedAt);

        if ($this->cookieJar($current) === null) {
            return $result;
        }

        return new CommunicationResult(
            $result->successful,
            $result->statusCode,
            $result->error,
            $result->response,
            [...$result->metadata, '_cookie_provenance_managed' => true],
        );
    }

    private function applyCookiesForHop(HttpRequest $request, string $url): HttpRequest
    {
        $jar = $this->cookieJar($request);
        if ($jar === null) {
            return $request;
        }

        $request = $request->withoutHeader('Cookie');
        $originUrl = $request->metadata['_cookie_origin_url'] ?? null;
        $originHeader = $this->cookieHeaderFromMetadata($request->metadata['_cookie_origin_header'] ?? null);
        if (is_string($originUrl) && $this->sameOrigin($originUrl, $url) && $originHeader !== null) {
            $request = $request->header('Cookie', $originHeader);
        }

        return $jar->applyToRequest($request);
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

        return CurlResultFactory::fromExecution(
            $request,
            'curl',
            $body,
            $errno,
            $bodyCollector->error() ?? $error,
            $info,
            $headerCollector->headers(),
            publishBufferedDownload: false,
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
            $bodyCollector?->abort();

            return CommunicationResult::failure($exception->getMessage(), metadata: ['transport' => 'curl']);
        }

        return [
            'request' => $request,
            'headerCollector' => $headerCollector,
            'bodyCollector' => $bodyCollector,
        ];
    }

    /**
     * @return string|list<string>|null
     */
    private function cookieHeaderFromMetadata(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        $headers = [];
        foreach ($value as $header) {
            if (!is_string($header)) {
                return null;
            }

            $headers[] = $header;
        }

        return $headers === [] ? null : $headers;
    }

    private function cookieJar(HttpRequest $request): ?CookieJar
    {
        $jar = $request->metadata['_cookie_jar'] ?? null;

        return $jar instanceof CookieJar ? $jar : null;
    }

    private function dispatchResultEvents(HttpRequest $request, CommunicationResult $result, float $startedAt): void
    {
        $payload = [
            'method' => $request->method->value,
            'url' => HttpRedactor::redactUrl($request->buildUrl(), $request->sensitiveQueryNames()),
            'status' => $result->statusCode,
            'successful' => $result->successful,
            'duration_ms' => (int) (($this->clock->monotonic() - $startedAt) * 1000),
            'transport' => 'curl',
        ];

        if ($result->successful) {
            $this->events->dispatch('http.request.finish', $payload);

            return;
        }

        $this->events->dispatch('http.request.failed', [
            ...$payload,
            'failure_category' => ObservabilitySanitizer::resultContext($result)['failure_category'] ?? 'transport_error',
        ]);
    }

    private function dispatchStartEvent(HttpRequest $request, string $url): void
    {
        $this->events->dispatch('http.request.start', [
            'method' => $request->method->value,
            'url' => HttpRedactor::redactUrl($url, $request->sensitiveQueryNames()),
            'headers' => HttpRedactor::redactHeaders($request->headers->all(), $request->sensitiveHeaderNames()),
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

        if (!$result->successful) {
            $bodyCollector->abort();

            return $result;
        }

        $commitError = $bodyCollector->commit();
        if ($commitError !== null) {
            return CommunicationResult::failure(
                $commitError,
                $result->statusCode,
                $result->response,
                $result->metadata,
            );
        }

        return $result;
    }

    private function prepareCookieContext(HttpRequest $request): HttpRequest
    {
        if ($this->cookieJar($request) === null || array_key_exists('_cookie_origin_url', $request->metadata)) {
            return $request;
        }

        return $request->metadata([
            ...$request->metadata,
            '_cookie_origin_url' => $request->buildUrl(),
            '_cookie_origin_header' => $request->headers->get('Cookie'),
        ]);
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

    private function storeResponseCookies(HttpRequest $request, HttpResponse $response, string $url): void
    {
        $this->cookieJar($request)?->storeFromResponse($response, $url);
    }
}
