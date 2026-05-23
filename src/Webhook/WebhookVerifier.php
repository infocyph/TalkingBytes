<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Signing\HmacSha256Signer;
use InvalidArgumentException;

final readonly class WebhookVerifier
{
    public function __construct(
        private string $secret,
        private int $maxAgeSeconds = 300,
    ) {
        if ($this->secret === '') {
            throw new InvalidArgumentException('Webhook secret must not be empty.');
        }

        if ($this->maxAgeSeconds < 1) {
            throw new InvalidArgumentException('Webhook max age must be greater than zero.');
        }
    }

    public function verify(string $payload, string $signatureHeader, ?int $now = null): bool
    {
        $parts = $this->parseSignatureHeader($signatureHeader);
        if ($parts === null) {
            return false;
        }

        $timestamp = $parts['timestamp'];
        $signature = $parts['signature'];
        $now ??= time();

        if (abs($now - $timestamp) > $this->maxAgeSeconds) {
            return false;
        }

        $expected = new HmacSha256Signer($this->secret)->sign($timestamp . '.' . $payload);

        return hash_equals($expected, $signature);
    }

    /**
     * @return array{timestamp:int,signature:string}|null
     */
    private function parseSignatureHeader(string $value): ?array
    {
        $segments = explode(',', $value);
        $timestamp = null;
        $signature = null;

        foreach ($segments as $segment) {
            $item = explode('=', trim($segment), 2);
            if (count($item) !== 2) {
                continue;
            }

            if ($item[0] === 't') {
                if (ctype_digit($item[1])) {
                    $timestamp = (int) $item[1];
                }
            }

            if ($item[0] === 'v1') {
                if (preg_match('/^[a-f0-9]{64}$/i', $item[1]) === 1) {
                    $signature = strtolower($item[1]);
                }
            }
        }

        if ($timestamp === null || $signature === null) {
            return null;
        }

        return ['timestamp' => $timestamp, 'signature' => $signature];
    }
}
