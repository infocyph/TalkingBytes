<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Webhook\Model\WebhookVerificationResult;
use Infocyph\TalkingBytes\Webhook\Signing\HmacWebhookSigner;
use Infocyph\TalkingBytes\Webhook\Signing\WebhookSignatureParser;
use InvalidArgumentException;

final readonly class WebhookVerifier
{
    private WebhookSignatureParser $signatureParser;

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

        $this->signatureParser = new WebhookSignatureParser();
    }

    public function verify(
        string $payload,
        string $signatureHeader,
        ?int $now = null,
        ?string $timestampHeader = null,
    ): bool {
        return $this->verifyResult($payload, $signatureHeader, $timestampHeader, $now)->valid;
    }

    public function verifyResult(
        string $payload,
        string $signatureHeader,
        ?string $timestampHeader = null,
        ?int $now = null,
    ): WebhookVerificationResult {
        $now ??= time();

        if (trim($signatureHeader) === '') {
            return $this->reject('missing_signature_header');
        }

        if ($timestampHeader !== null && trim($timestampHeader) === '') {
            return $this->reject('missing_timestamp');
        }

        $parsed = $this->signatureParser->parse($signatureHeader, $timestampHeader);
        if ($parsed === null) {
            if ($timestampHeader !== null && trim($timestampHeader) !== '' && !ctype_digit(trim($timestampHeader))) {
                return $this->reject('invalid_timestamp');
            }

            return $this->reject('malformed_signature');
        }

        $timestamp = $parsed['timestamp'];
        $signature = $parsed['signature'];

        if (abs($now - $timestamp) > $this->maxAgeSeconds) {
            return $this->reject('expired_timestamp', $timestamp, $signature);
        }

        $expected = new HmacWebhookSigner()->sign($payload, $timestamp, $this->secret);
        if (!hash_equals($expected, $signature)) {
            return $this->reject('signature_mismatch', $timestamp, $signature);
        }

        $result = new WebhookVerificationResult(
            valid: true,
            timestamp: $timestamp,
            signaturePresent: true,
            signaturePrefix: substr($signature, 0, 8),
            metadata: ['max_age_seconds' => $this->maxAgeSeconds],
        );

        CommunicationEventBus::dispatch('webhook.verified', [
            'timestamp' => $timestamp,
            'signature' => '[REDACTED]',
        ]);

        return $result;
    }

    private function reject(string $reason, ?int $timestamp = null, ?string $signature = null): WebhookVerificationResult
    {
        CommunicationEventBus::dispatch('webhook.rejected', [
            'reason' => $reason,
            'timestamp' => $timestamp,
            'signature' => $signature !== null ? '[REDACTED]' : null,
        ]);

        return new WebhookVerificationResult(
            valid: false,
            reason: $reason,
            timestamp: $timestamp,
            signaturePresent: $signature !== null,
            signaturePrefix: $signature !== null ? substr($signature, 0, 8) : null,
            metadata: ['max_age_seconds' => $this->maxAgeSeconds],
        );
    }
}
