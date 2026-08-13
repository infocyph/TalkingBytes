<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Signing;

final class WebhookSignatureParser
{
    public const int MAX_HEADER_BYTES = 8192;

    public const int MAX_SEGMENTS = 32;

    public const int MAX_SIGNATURES = 8;

    /**
     * @return array{timestamp:int,signatures:list<string>}|null
     */
    public function parse(string $signatureHeader, ?string $timestampHeader = null): ?array
    {
        if (strlen($signatureHeader) > self::MAX_HEADER_BYTES) {
            return null;
        }

        $timestamp = $this->parseOptionalTimestamp($timestampHeader);
        if ($timestampHeader !== null && $timestampHeader !== '' && $timestamp === null) {
            return null;
        }

        $segments = explode(',', $signatureHeader);
        if (count($segments) > self::MAX_SEGMENTS) {
            return null;
        }

        $signatures = [];
        foreach ($segments as $segment) {
            [$timestamp, $candidate] = $this->parseSegment($segment, $timestamp);
            if ($candidate !== null) {
                $signatures[] = $candidate;
                if (count($signatures) > self::MAX_SIGNATURES) {
                    return null;
                }
            }
        }

        if ($timestamp === null || $signatures === []) {
            return null;
        }

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }

    private function parseOptionalTimestamp(?string $timestampHeader): ?int
    {
        if ($timestampHeader === null || $timestampHeader === '') {
            return null;
        }

        $trimmedTimestamp = trim($timestampHeader);
        if (!ctype_digit($trimmedTimestamp)) {
            return null;
        }

        return (int) $trimmedTimestamp;
    }

    /**
     * @return array{0:?int,1:?string}
     */
    private function parseSegment(string $segment, ?int $timestamp): array
    {
        $parts = explode('=', trim($segment), 2);
        if (count($parts) !== 2) {
            return [$timestamp, null];
        }

        if ($parts[0] === 't' && $timestamp === null) {
            $candidate = trim($parts[1]);
            if (ctype_digit($candidate)) {
                return [(int) $candidate, null];
            }

            return [$timestamp, null];
        }

        if ($parts[0] !== 'v1') {
            return [$timestamp, null];
        }

        $candidate = strtolower(trim($parts[1]));
        if (preg_match('/^[a-f0-9]{64}$/', $candidate) === 1) {
            return [$timestamp, $candidate];
        }

        return [$timestamp, null];
    }
}
