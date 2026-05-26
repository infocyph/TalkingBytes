<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Native;

use Infocyph\TalkingBytes\Grpc\GrpcDeadline;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;

final readonly class GeneratedStubGrpcInvoker implements NativeGrpcInvoker, NativeGrpcStreamingInvoker
{
    /**
     * @param array<string, string> $methodMap Maps gRPC method path to stub method name.
     */
    public function __construct(
        private object $stubClient,
        private array $methodMap = [],
    ) {}

    public function bidiStream(
        string $method,
        iterable $messages,
        GrpcMetadata $headers,
        callable $onMessage,
        ?float $deadlineSeconds = null,
    ): NativeGrpcResult {
        $call = $this->invokeStreamOpen($method, $headers, $deadlineSeconds);
        $this->writeMessages($call, $messages);
        $this->finishClientWrites($call);
        $this->drainInboundStream($call, $onMessage);

        return $this->finalizeCall($call);
    }

    public function clientStream(
        string $method,
        iterable $messages,
        GrpcMetadata $headers,
        ?float $deadlineSeconds = null,
    ): NativeGrpcResult {
        $call = $this->invokeStreamOpen($method, $headers, $deadlineSeconds);
        $this->writeMessages($call, $messages);
        $this->finishClientWrites($call);

        return $this->finalizeCall($call);
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
        $call = $this->invokeStubMethod(
            $this->resolveMethodName($method),
            [
                $message,
                $this->toNativeMetadata($headers),
                $this->toNativeOptions($deadlineSeconds),
            ],
        );

        $this->drainInboundStream($call, $onMessage);

        return $this->finalizeCall($call);
    }

    private function drainInboundStream(mixed $call, callable $onMessage): void
    {
        if (!is_object($call)) {
            throw new \RuntimeException('gRPC stream call result must be an object.');
        }

        if (method_exists($call, 'responses')) {
            $responses = $call->responses();
            if (!is_iterable($responses)) {
                throw new \RuntimeException('gRPC server stream responses() must return iterable.');
            }

            foreach ($responses as $response) {
                $onMessage($response);
            }

            return;
        }

        if (method_exists($call, 'read')) {
            while (true) {
                $response = $call->read();
                if ($response === null) {
                    break;
                }

                $onMessage($response);
            }

            return;
        }

        throw new \RuntimeException('Unsupported gRPC stream call object: expected responses() or read().');
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
            throw new \RuntimeException('Unsupported gRPC call object: expected wait() method.');
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
        $metadata = $this->toNativeMetadata($headers);
        $options = $this->toNativeOptions($deadlineSeconds);

        try {
            return $this->invokeStubMethod($methodName, [$metadata, $options]);
        } catch (\ArgumentCountError|\TypeError) {
            return $this->invokeStubMethod($methodName, [null, $metadata, $options]);
        }
    }

    /**
     * @param list<mixed> $args
     */
    private function invokeStubMethod(string $methodName, array $args): mixed
    {
        if (!method_exists($this->stubClient, $methodName)) {
            throw new \RuntimeException(sprintf(
                'Generated gRPC stub method "%s" was not found on %s.',
                $methodName,
                $this->stubClient::class,
            ));
        }

        return $this->stubClient->{$methodName}(...$args);
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
        $mapped = $this->methodMap[$method] ?? null;
        if (is_string($mapped) && $mapped !== '') {
            return $mapped;
        }

        $trimmed = ltrim($method, '/');
        $parts = explode('/', $trimmed);

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
            throw new \RuntimeException('Unsupported client stream call object: expected write() method.');
        }

        foreach ($messages as $message) {
            $call->write($message);
        }
    }
}
