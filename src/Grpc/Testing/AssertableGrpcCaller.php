<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Testing;

use Infocyph\TalkingBytes\Grpc\GrpcRequest;
use LogicException;

final readonly class AssertableGrpcCaller
{
    public function __construct(private FakeGrpcCaller $fake) {}

    public function assertCallCount(int $expected): void
    {
        $actual = count($this->fake->requests());
        if ($actual !== $expected) {
            throw new LogicException(sprintf('Expected %d gRPC call(s), got %d.', $expected, $actual));
        }
    }

    public function assertCalledMethod(string $method): void
    {
        foreach ($this->fake->requests() as $request) {
            if ($request->method === $method) {
                return;
            }
        }

        throw new LogicException(sprintf('Expected gRPC method "%s" to be called.', $method));
    }

    /**
     * @param callable(GrpcRequest):bool $assertion
     */
    public function assertCalledWhere(callable $assertion): void
    {
        foreach ($this->fake->requests() as $request) {
            if ($assertion($request)) {
                return;
            }
        }

        throw new LogicException('Expected at least one gRPC call matching the assertion.');
    }

    public function assertCalledWithMessage(string $method, mixed $message): void
    {
        $this->assertCalledWhere(static fn(GrpcRequest $request): bool => $request->method === $method
            && $request->message === $message);
    }

    public function assertCalledWithMetadata(string $method, string $headerName, string $value): void
    {
        $this->assertCalledWhere(static function (GrpcRequest $request) use ($method, $headerName, $value): bool {
            if ($request->method !== $method) {
                return false;
            }

            return in_array($value, $request->headers->values($headerName), true);
        });
    }

    public function assertNothingCalled(): void
    {
        if ($this->fake->requests() !== []) {
            throw new LogicException('Expected no gRPC calls to be made.');
        }
    }

    public function firstRequest(): ?GrpcRequest
    {
        return $this->fake->requests()[0] ?? null;
    }

    public function lastRequest(): ?GrpcRequest
    {
        $requests = $this->fake->requests();
        if ($requests === []) {
            return null;
        }

        return $requests[array_key_last($requests)];
    }
}
