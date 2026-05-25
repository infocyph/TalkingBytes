<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Signing;

final class WebhookSignatureParser
{
    /**
     * @return array{timestamp:int,signature:string}|null
     */
    public function parse(string $signatureHeader, ?string $timestampHeader = null): ?array
    {
        $timestamp = $this->parseOptionalTimestamp($timestampHeader);
        if ($timestampHeader !== null && $timestampHeader !== '' && $timestamp === null) {
            return null;
        }

        $signature = null;

        $segments = explode(',', $signatureHeader);
        foreach ($segments as $segment) {
            [$timestamp, $signature] = $this->parseSegment($segment, $timestamp, $signature);
        }

        if ($timestamp === null || $signature === null) {
            return null;
        }

        return ['timestamp' => $timestamp, 'signature' => $signature];
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
    private function parseSegment(string $segment, ?int $timestamp, ?string $signature): array
    {
        $parts = explode('=', trim($segment), 2);
        if (count($parts) !== 2) {
            return [$timestamp, $signature];
        }

        if ($parts[0] === 't' && $timestamp === null) {
            $candidate = trim($parts[1]);
            if (ctype_digit($candidate)) {
                return [(int) $candidate, $signature];
            }

            return [$timestamp, $signature];
        }

        if ($parts[0] !== 'v1') {
            return [$timestamp, $signature];
        }

        $candidate = strtolower(trim($parts[1]));
        if (preg_match('/^[a-f0-9]{64}$/', $candidate) === 1) {
            return [$timestamp, $candidate];
        }

        return [$timestamp, $signature];
    }
}
