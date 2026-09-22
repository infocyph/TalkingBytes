<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Native;

use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Grpc\GrpcDeadline;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcMethodGuard;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionObject;
use RuntimeException;
use Throwable;

final readonly class GeneratedStubGrpcInvoker implements NativeGrpcInvoker, NativeGrpcStreamingInvoker
{
    /** @var array<string, string> */
    private array $methodMap;

    /** @var array<string, int> */
    private array $streamOpenArity;

    /**
     * @param array<string, string> $methodMap Maps gRPC method path to stub method name.
     */
    public function __construct(
        private object $stubClient,
        array $methodMap = [],
        private ?CancellationSignal $cancellation = null,
    ) {
        $reflection = new ReflectionObject($this->stubClient);
        $this->methodMap = $this->normalizeMethodMap($reflection, $methodMap);

        $streamOpenArity = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            $streamOpenArity[$method->getName()] = self::resolveStreamOpenArity($method);
        }

        $this->streamOpenArity = $streamOpenArity;
    }

    public function bidiStream(
        string $method,
        iterable $messages,
        GrpcMetadata $headers,
        callable $onMessage,
        ?float $deadlineSeconds = null,
    ): NativeGrpcResult {
        $this->assertNotCancelled();
        $call = $this->invokeStreamOpen($method, $headers, $deadlineSeconds);

        try {
            $this->writeMessages($call, $messages);
            $this->assertNotCancelled();
            $this->finishClientWrites($call);
            $this->drainInboundStream($call, $onMessage);
            $this->assertNotCancelled();

            return $this->finalizeCall($call);
        } catch (Throwable $exception) {
            $this->cancelCall($call);

            throw $exception;
        }
    }

    public function clientStream(
        string $method,
        iterable $messages,
        GrpcMetadata $headers,
        ?float $deadlineSeconds = null,
    ): NativeGrpcResult {
        $this->assertNotCancelled();
        $call = $this->invokeStreamOpen($method, $headers, $deadlineSeconds);

        try {
            $this->writeMessages($call, $messages);
            $this->assertNotCancelled();
            $this->finishClientWrites($call);
            $this->assertNotCancelled();

            return $this->finalizeCall($call);
        } catch (Throwable $exception) {
            $this->cancelCall($call);

            throw $exception;
        }
    }

    public function invoke(
        string $method,
        mixed $message,
        GrpcMetadata $headers,
        ?float $deadlineSeconds = null,
    ): NativeGrpcResult {
        $call = $this->invokeStubMethod(
            $this->resolveMethodName($method),
            [
                $message,
                $this->toNativeMetadata($headers),
                $this->toNativeOptions($deadlineSeconds),
            ],
        );

        return $this->finalizeCall($call);
    }

    public function serverStream(
        string $method,
        mixed $message,
        GrpcMetadata $headers,
        callable $onMessage,
        ?float $deadlineSeconds = null,
    ): NativeGrpcResult {
        $this->assertNotCancelled();
        $call = $this->invokeStubMethod(
            $this->resolveMethodName($method),
            [
                $message,
                $this->toNativeMetadata($headers),
                $this->toNativeOptions($deadlineSeconds),
            ],
        );

        try {
            $this->drainInboundStream($call, $onMessage);
            $this->assertNotCancelled();

            return $this->finalizeCall($call);
        } catch (Throwable $exception) {
            $this->cancelCall($call);

            throw $exception;
        }
    }

    private static function resolveStreamOpenArity(ReflectionMethod $method): int
    {
        if ($method->isVariadic()) {
            return $method->getNumberOfParameters() >= 3 ? 3 : 2;
        }

        return match (true) {
            $method->getNumberOfParameters() >= 3 => 3,
            $method->getNumberOfParameters() === 2 => 2,
            default => 0,
        };
    }

    private function assertNotCancelled(): void
    {
        if ($this->cancellation?->isRequested() === true) {
            throw new RuntimeException('gRPC stream operation cancelled.');
        }
    }

    private function cancelCall(mixed $call): void
    {
        if (!is_object($call) || !method_exists($call, 'cancel')) {
            return;
        }

        try {
            $call->cancel();
        } catch (Throwable) {
            // Preserve the original stream failure/cancellation.
        }
    }

    private function drainInboundStream(mixed $call, callable $onMessage): void
    {
        if (!is_object($call)) {
            throw new RuntimeException('gRPC stream call result must be an object.');
        }

        if (method_exists($call, 'responses')) {
            $this->assertNotCancelled();
            $responses = $call->responses();
            if (!is_iterable($responses)) {
                throw new RuntimeException('gRPC server stream responses() must return iterable.');
            }

            foreach ($responses as $response) {
                $this->assertNotCancelled();
                $onMessage($response);
            }

            return;
        }

        if (method_exists($call, 'read')) {
            while (true) {
                $this->assertNotCancelled();
                $response = $call->read();
                if ($response === null) {
                    break;
                }

                $this->assertNotCancelled();
                $onMessage($response);
            }

            return;
        }

        throw new RuntimeException('Unsupported gRPC stream call object: expected responses() or read().');
    }

    private function extractHeaders(mixed $call): GrpcMetadata
    {
        if (!is_object($call)) {
            return new GrpcMetadata();
        }

        if (method_exists($call, 'getMetadata')) {
            return $this->fromNativeMetadata($call->getMetadata());
        }

        return new GrpcMetadata();
    }

    /**
     * @param array<mixed> $status
     */
    private function extractStatusCodeFromArray(array $status): int
    {
        if (isset($status['code']) && is_int($status['code'])) {
            return $status['code'];
        }

        if (isset($status[0]) && is_int($status[0])) {
            return $status[0];
        }

        return GrpcStatus::Unknown->value;
    }

    private function extractStatusCodeFromObject(object $status): int
    {
        if (isset($status->code) && is_int($status->code)) {
            return $status->code;
        }

        if (!method_exists($status, 'getCode')) {
            return GrpcStatus::Unknown->value;
        }

        $code = $status->getCode();

        return is_int($code) ? $code : GrpcStatus::Unknown->value;
    }

    private function extractTrailers(mixed $call): GrpcMetadata
    {
        if (!is_object($call)) {
            return new GrpcMetadata();
        }

        if (method_exists($call, 'getTrailingMetadata')) {
            return $this->fromNativeMetadata($call->getTrailingMetadata());
        }

        return new GrpcMetadata();
    }

    private function extractWaitStatusCode(mixed $status): int
    {
        if (is_int($status)) {
            return $status;
        }

        if (is_array($status)) {
            return $this->extractStatusCodeFromArray($status);
        }

        if (is_object($status)) {
            return $this->extractStatusCodeFromObject($status);
        }

        return GrpcStatus::Unknown->value;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractWaitStatusMetadata(mixed $status): array
    {
        if (is_array($status)) {
            return $this->normalizeStatusArrayMetadata($status);
        }

        if (!is_object($status)) {
            return [];
        }

        $metadata = [];
        if (isset($status->details) && is_string($status->details)) {
            $metadata['details'] = $status->details;
        }

        if (isset($status->metadata) && is_array($status->metadata)) {
            $metadata['status_metadata'] = $status->metadata;
        }

        return $metadata;
    }

    private function finalizeCall(mixed $call): NativeGrpcResult
    {
        if (!is_object($call) || !method_exists($call, 'wait')) {
            throw new RuntimeException('Unsupported gRPC call object: expected wait() method.');
        }

        $wait = $call->wait();

        $message = null;
        $status = GrpcStatus::Unknown->value;
        $metadata = [];

        if (is_array($wait)) {
            $message = $wait[0] ?? null;
            $statusRaw = $wait[1] ?? null;
            $status = $this->extractWaitStatusCode($statusRaw);
            $metadata = $this->extractWaitStatusMetadata($statusRaw);
        } elseif ($wait !== null) {
            $message = $wait;
            $status = GrpcStatus::Ok->value;
        }

        return new NativeGrpcResult(
            statusCode: $status,
            message: $message,
            headers: $this->extractHeaders($call),
            trailers: $this->extractTrailers($call),
            metadata: $metadata,
        );
    }

    private function finishClientWrites(mixed $call): void
    {
        if (!is_object($call)) {
            return;
        }

        if (method_exists($call, 'writesDone')) {
            $call->writesDone();

            return;
        }

        if (method_exists($call, 'closeWrite')) {
            $call->closeWrite();
        }
    }

    private function fromNativeMetadata(mixed $metadata): GrpcMetadata
    {
        if ($metadata instanceof GrpcMetadata) {
            return $metadata;
        }

        if (!is_array($metadata)) {
            return new GrpcMetadata();
        }

        /** @var array<string, list<string>> $normalized */
        $normalized = [];

        foreach ($metadata as $name => $values) {
            if (!is_string($name)) {
                continue;
            }

            $normalizedValues = $this->normalizeNativeMetadataValues($values);
            $normalized[$name] = $normalizedValues;
        }

        return new GrpcMetadata($normalized);
    }

    private function invokeStreamOpen(string $method, GrpcMetadata $headers, ?float $deadlineSeconds): mixed
    {
        $methodName = $this->resolveMethodName($method);
        $arity = $this->streamOpenArity[$methodName] ?? 0;
        if ($arity === 0) {
            throw new RuntimeException(sprintf(
                'Unsupported generated gRPC stream signature for method "%s".',
                $methodName,
            ));
        }

        $metadata = $this->toNativeMetadata($headers);
        $options = $this->toNativeOptions($deadlineSeconds);
        $arguments = $arity === 3
            ? [null, $metadata, $options]
            : [$metadata, $options];

        return $this->invokeStubMethod($methodName, $arguments);
    }

    /**
     * @param list<mixed> $args
     */
    private function invokeStubMethod(string $methodName, array $args): mixed
    {
        if (!is_callable([$this->stubClient, $methodName])) {
            throw new RuntimeException(sprintf(
                'Generated gRPC stub method "%s" was not found or is not public on %s.',
                $methodName,
                $this->stubClient::class,
            ));
        }

        return $this->stubClient->{$methodName}(...$args);
    }

    /**
     * @param array<string, string> $methodMap
     * @return array<string, string>
     */
    private function normalizeMethodMap(ReflectionObject $reflection, array $methodMap): array
    {
        $normalized = [];

        foreach ($methodMap as $method => $stubMethod) {
            if (!is_string($method) || !is_string($stubMethod) || trim($stubMethod) === '') {
                throw new InvalidArgumentException('gRPC generated method maps require non-empty string keys and values.');
            }

            $normalizedMethod = GrpcMethodGuard::normalize($method);
            if (isset($normalized[$normalizedMethod])) {
                throw new InvalidArgumentException(sprintf(
                    'Duplicate generated gRPC method mapping for "%s".',
                    $normalizedMethod,
                ));
            }

            if (!$reflection->hasMethod($stubMethod)) {
                throw new InvalidArgumentException(sprintf(
                    'Generated gRPC stub method "%s" was not found on %s.',
                    $stubMethod,
                    $this->stubClient::class,
                ));
            }

            $reflected = $reflection->getMethod($stubMethod);
            if (!$reflected->isPublic() || $reflected->isStatic()) {
                throw new InvalidArgumentException(sprintf(
                    'Generated gRPC stub method "%s" must be a public instance method.',
                    $stubMethod,
                ));
            }

            $normalized[$normalizedMethod] = $stubMethod;
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeNativeMetadataValues(mixed $values): array
    {
        if (is_array($values)) {
            $normalized = [];

            foreach ($values as $value) {
                $normalizedValue = $this->stringifyNativeMetadataValue($value);
                if ($normalizedValue !== null) {
                    $normalized[] = $normalizedValue;
                }
            }

            return $normalized;
        }

        $normalizedValue = $this->stringifyNativeMetadataValue($values);
        if ($normalizedValue === null) {
            return [];
        }

        return [$normalizedValue];
    }

    /**
     * @param array<mixed> $status
     * @return array<string, mixed>
     */
    private function normalizeStatusArrayMetadata(array $status): array
    {
        $normalized = [];

        foreach ($status as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function resolveMethodName(string $method): string
    {
        $normalized = GrpcMethodGuard::normalize($method);
        $mapped = $this->methodMap[$normalized] ?? null;
        if ($mapped !== null) {
            return $mapped;
        }

        $parts = explode('/', ltrim($normalized, '/'));

        return $parts[array_key_last($parts)];
    }

    private function stringifyNativeMetadataValue(mixed $value): ?string
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function toNativeMetadata(GrpcMetadata $headers): array
    {
        return $headers->headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function toNativeOptions(?float $deadlineSeconds): array
    {
        if ($deadlineSeconds === null) {
            return [];
        }

        return ['timeout' => GrpcDeadline::secondsToMicros($deadlineSeconds)];
    }

    /**
     * @param iterable<mixed> $messages
     */
    private function writeMessages(mixed $call, iterable $messages): void
    {
        if (!is_object($call) || !method_exists($call, 'write')) {
            throw new RuntimeException('Unsupported client stream call object: expected write() method.');
        }

        foreach ($messages as $message) {
            $this->assertNotCancelled();
            $call->write($message);
        }
    }
}
