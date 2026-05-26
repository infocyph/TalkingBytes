<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Testing;

use Infocyph\TalkingBytes\Http\Body\FormBody;
use Infocyph\TalkingBytes\Http\Body\JsonBody;
use Infocyph\TalkingBytes\Http\Body\MultipartBody;
use Infocyph\TalkingBytes\Http\Body\RawBody;
use Infocyph\TalkingBytes\Http\Enum\HttpMethod;
use Infocyph\TalkingBytes\Http\HttpRequest;
use RuntimeException;

final readonly class AssertableHttpTransport
{
    public function __construct(private FakeHttpTransport $fakeTransport) {}

    public function assertBodyContains(string $fragment): void
    {
        $this->assertRequestedWhere(
            static function (HttpRequest $request) use ($fragment): bool {
                if ($request->body === null) {
                    return false;
                }

                $payload = $request->body->toCurlPayload();

                return is_string($payload) && str_contains($payload, $fragment);
            },
            sprintf('Expected a request body containing "%s".', $fragment),
        );
    }

    public function assertForm(): void
    {
        $this->assertRequestedWhere(
            static fn(HttpRequest $request): bool => $request->body instanceof FormBody,
            'Expected at least one form request body.',
        );
    }

    public function assertHeader(string $name, string $value): void
    {
        $this->assertRequestedWhere(
            static fn(HttpRequest $request): bool => $request->headers->get($name) === $value,
            sprintf('Expected a request with header "%s: %s".', $name, $value),
        );
    }

    public function assertJson(): void
    {
        $this->assertRequestedWhere(
            static fn(HttpRequest $request): bool => $request->body instanceof JsonBody,
            'Expected at least one JSON request body.',
        );
    }

    public function assertMultipartFile(string $field): void
    {
        $this->assertRequestedWhere(
            static fn(HttpRequest $request): bool => $request->body instanceof MultipartBody
                && array_any(
                    array_keys($request->body->toCurlPayload()),
                    static fn(string $name): bool => $name === $field || str_starts_with($name, $field . '['),
                ),
            sprintf('Expected multipart request containing field "%s".', $field),
        );
    }

    public function assertNothingRequested(): void
    {
        if ($this->fakeTransport->sentRequests() !== []) {
            throw new RuntimeException('Expected no HTTP request to be sent.');
        }
    }

    public function assertRequestCount(int $count): void
    {
        $actual = count($this->fakeTransport->sentRequests());

        if ($actual !== $count) {
            throw new RuntimeException(sprintf('Expected %d HTTP request(s), got %d.', $count, $actual));
        }
    }

    public function assertRequested(HttpMethod|string $method, string $url): void
    {
        $expectedMethod = $method instanceof HttpMethod ? $method : HttpMethod::from(strtoupper($method));

        $this->assertRequestedWhere(
            static fn(HttpRequest $request): bool => $request->method === $expectedMethod && $request->buildUrl() === $url,
            sprintf('Expected request "%s %s" to be sent.', $expectedMethod->value, $url),
        );
    }

    public function assertRequestedWhere(callable $predicate, string $message = 'Expected an HTTP request matching predicate.'): void
    {
        foreach ($this->fakeTransport->sentRequests() as $request) {
            if ($predicate($request) === true) {
                return;
            }
        }

        throw new RuntimeException($message);
    }

    public function firstRequest(): ?HttpRequest
    {
        $requests = $this->fakeTransport->sentRequests();

        return $requests[0] ?? null;
    }

    public function lastRequest(): ?HttpRequest
    {
        $requests = $this->fakeTransport->sentRequests();

        return $requests === [] ? null : $requests[array_key_last($requests)];
    }

    public function requestBodyType(): ?string
    {
        $request = $this->lastRequest();
        if ($request === null || $request->body === null) {
            return null;
        }

        return match (true) {
            $request->body instanceof JsonBody => 'json',
            $request->body instanceof FormBody => 'form',
            $request->body instanceof MultipartBody => 'multipart',
            $request->body instanceof RawBody => 'raw',
            default => 'unknown',
        };
    }
}
