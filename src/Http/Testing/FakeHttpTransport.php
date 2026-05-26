<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Testing;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\HttpResponse;

final class FakeHttpTransport implements TransportInterface
{
    /**
     * @var list<CommunicationResult>
     */
    private array $queuedResults = [];

    /**
     * @var list<HttpRequest>
     */
    private array $sentRequests = [];

    public function push(CommunicationResult $result): self
    {
        $this->queuedResults[] = $result;

        return $this;
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed> $metadata
     */
    public function pushJson(mixed $payload, int $statusCode = 200, array $headers = [], array $metadata = []): self
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $responseHeaders = $headers;
        if (!array_any(array_keys($responseHeaders), static fn(string $name): bool => strcasecmp($name, 'Content-Type') === 0)) {
            $responseHeaders['Content-Type'] = 'application/json';
        }

        $response = new HttpResponse(
            statusCode: $statusCode,
            body: $encoded,
            headers: $responseHeaders,
            metadata: $metadata,
        );

        $result = $statusCode >= 400
            ? CommunicationResult::failure(
                error: sprintf('HTTP request failed with status code %d.', $statusCode),
                statusCode: $statusCode,
                response: $response,
                metadata: $metadata,
            )
            : CommunicationResult::success(
                statusCode: $statusCode,
                response: $response,
                metadata: $metadata,
            );

        return $this->push($result);
    }

    public function send(CommunicationRequest $request): CommunicationResult
    {
        if (!$request->payload instanceof HttpRequest) {
            return CommunicationResult::failure('FakeHttpTransport expects HttpRequest payload.');
        }

        $this->sentRequests[] = $request->payload;

        if ($this->queuedResults === []) {
            return CommunicationResult::success(
                response: new HttpResponse(
                    statusCode: 200,
                    body: '',
                    headers: [],
                    metadata: ['transport' => 'fake-http'],
                ),
                statusCode: 200,
                metadata: ['transport' => 'fake-http'],
            );
        }

        /** @var CommunicationResult $result */
        $result = array_shift($this->queuedResults);

        return $result;
    }

    /**
     * @return list<HttpRequest>
     */
    public function sentRequests(): array
    {
        return $this->sentRequests;
    }

    public function wasRequested(Closure $predicate): bool
    {
        return array_any($this->sentRequests, static fn(HttpRequest $request): bool => $predicate($request) === true);
    }
}
