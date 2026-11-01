<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Native;

use Infocyph\TalkingBytes\Grpc\GrpcMetadata;

/** Normalizes generated gRPC extension metadata at the native transport boundary. */
final class NativeGrpcMetadataCodec
{
    public static function fromNative(mixed $metadata): GrpcMetadata
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
            if (is_string($name)) {
                $normalized[$name] = self::normalizeValues($values);
            }
        }

        return new GrpcMetadata($normalized);
    }

    /**
     * @param array<mixed> $status
     * @return array<string, mixed>
     */
    public static function normalizeStatusArray(array $status): array
    {
        $normalized = [];
        foreach ($status as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private static function normalizeValues(mixed $values): array
    {
        if (is_array($values)) {
            $normalized = [];
            foreach ($values as $value) {
                $string = self::stringify($value);
                if ($string !== null) {
                    $normalized[] = $string;
                }
            }

            return $normalized;
        }

        $string = self::stringify($values);

        return $string === null ? [] : [$string];
    }

    private static function stringify(mixed $value): ?string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }
}
