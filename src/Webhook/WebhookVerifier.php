<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Webhook\Model\WebhookVerificationResult;
use Infocyph\TalkingBytes\Webhook\Signing\HmacWebhookSigner;
use Infocyph\TalkingBytes\Webhook\Signing\WebhookSignatureParser;
use InvalidArgumentException;

final readonly class WebhookVerifier
{
    private Clock $clock;

    private EventDispatcher $events;

    /** @var non-empty-list<string> */
    private array $secrets;

    private WebhookSignatureParser $signatureParser;

    private HmacWebhookSigner $signer;

    /** @param string|list<string> $secret */
    public function __construct(
        #[\SensitiveParameter]
        string|array $secret,
        private int $maxAgeSeconds = 300,
        private int $maxPayloadBytes = 1_048_576,
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
    ) {
        $secrets = is_string($secret) ? [$secret] : $secret;
        if ($secrets === [] || count($secrets) > 8 || array_any($secrets, static fn(string $value): bool => $value === '')) {
            throw new InvalidArgumentException('Configure between one and eight non-empty webhook secrets.');
        }

        if ($this->maxAgeSeconds < 1) {
            throw new InvalidArgumentException('Webhook max age must be greater than zero.');
        }
        if ($this->maxPayloadBytes < 1) {
            throw new InvalidArgumentException('Webhook max payload bytes must be greater than zero.');
        }

        /** @var non-empty-list<string> $secrets */
        $this->secrets = $secrets;
        $this->signatureParser = new WebhookSignatureParser();
        $this->signer = new HmacWebhookSigner();
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
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
        $now ??= (int) floor($this->clock->timestamp());

        if (strlen($payload) > $this->maxPayloadBytes) {
            return $this->reject('payload_too_large');
        }

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
        $signatures = $parsed['signatures'];

        $failure = $this->signatureFailure($payload, $timestamp, $signatures, $now);
        if ($failure !== null) {
            return $this->reject($failure, $timestamp, $signatures[0]);
        }

        $result = new WebhookVerificationResult(
            valid: true,
            timestamp: $timestamp,
            signaturePresent: true,
            signaturePrefix: substr($signatures[0], 0, 8),
            metadata: ['max_age_seconds' => $this->maxAgeSeconds, 'signature_count' => count($signatures)],
        );

        $this->events->dispatch('webhook.verified', [
            'timestamp' => $timestamp,
            'signature' => '[REDACTED]',
        ]);

        return $result;
    }

    private function reject(string $reason, ?int $timestamp = null, ?string $signature = null): WebhookVerificationResult
    {
        $this->events->dispatch('webhook.rejected', [
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

    /** @param list<string> $signatures */
    private function signatureFailure(string $payload, int $timestamp, array $signatures, int $now): ?string
    {
        if (abs($now - $timestamp) > $this->maxAgeSeconds) {
            return 'expired_timestamp';
        }

        $matched = false;
        foreach ($this->secrets as $secret) {
            $expected = $this->signer->sign($payload, $timestamp, $secret);
            foreach ($signatures as $signature) {
                $matched = hash_equals($expected, $signature) || $matched;
            }
        }

        return $matched ? null : 'signature_mismatch';
    }
}
