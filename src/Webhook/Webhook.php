<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Webhook\Contracts\WebhookReplayStore;
use Infocyph\TalkingBytes\Webhook\Testing\FakeWebhookSender;
use InvalidArgumentException;

final readonly class Webhook
{
    public static function fake(): FakeWebhookSender
    {
        return new FakeWebhookSender();
    }

    /** @param string|list<string> $secret */
    public static function receiver(#[\SensitiveParameter] string|array $secret, int $maxAgeSeconds = 300, ?EventDispatcher $events = null): WebhookReceiver
    {
        return new WebhookReceiver(new WebhookVerifier($secret, $maxAgeSeconds, events: $events), events: $events);
    }

    /**
     * @param string|list<string> $secret
     * @param array<string, mixed> $config
     */
    public static function receiverFromResolvedConfig(
        #[\SensitiveParameter]
        string|array $secret,
        array $config,
        ?WebhookReplayStore $replayStore = null,
        ?EventDispatcher $events = null,
    ): WebhookReceiver {
        $receiver = new WebhookReceiver(
            self::verifierFromResolvedConfig($secret, $config, $events),
            maxPayloadBytes: self::int($config, 'max_payload_bytes', 1_048_576),
            events: $events,
        );

        $replay = self::section($config, 'replay');
        $enabled = self::bool($replay, 'enabled', $replayStore !== null);
        if (!$enabled) {
            return $receiver;
        }

        if (!$replayStore instanceof WebhookReplayStore) {
            throw new InvalidArgumentException('Resolved webhook replay configuration requires a replay store.');
        }

        return $receiver->withReplayStore(
            $replayStore,
            self::int($replay, 'ttl_seconds', 86_400),
            self::string($replay, 'namespace', 'default'),
        );
    }

    public static function sender(HttpClient $httpClient, ?EventDispatcher $events = null): WebhookSender
    {
        return new WebhookSender($httpClient, events: $events);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function senderFromResolvedConfig(
        HttpClient $httpClient,
        array $config,
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
    ): WebhookSender {
        $sender = new WebhookSender(
            $httpClient,
            maxPayloadBytes: self::int($config, 'max_payload_bytes', 1_048_576),
            events: $events,
            clock: $clock,
            sleeper: $sleeper,
        );

        $secret = $config['signing_secret'] ?? null;
        if ($secret !== null) {
            if (!is_string($secret) || trim($secret) === '') {
                throw new InvalidArgumentException('Resolved webhook signing_secret must be a non-empty string.');
            }
            $sender = $sender->withSecret($secret);
        }

        $retry = self::section($config, 'retry');
        if (self::bool($retry, 'enabled', false)) {
            $sender = $sender->withRetryProfile(
                self::int($retry, 'attempts', 3),
                self::int($retry, 'base_delay_ms', 250),
                self::int($retry, 'max_retry_after_seconds', 30),
            );
        }

        return $sender;
    }

    /** @param string|list<string> $secret */
    public static function verifier(#[\SensitiveParameter] string|array $secret, int $maxAgeSeconds = 300, ?EventDispatcher $events = null): WebhookVerifier
    {
        return new WebhookVerifier($secret, $maxAgeSeconds, events: $events);
    }

    /**
     * @param string|list<string> $secret
     * @param array<string, mixed> $config
     */
    public static function verifierFromResolvedConfig(
        #[\SensitiveParameter]
        string|array $secret,
        array $config,
        ?EventDispatcher $events = null,
    ): WebhookVerifier {
        return self::verifier(
            $secret,
            self::int($config, 'max_age_seconds', 300),
            $events,
        );
    }

    /** @param array<string, mixed> $config */
    private static function bool(array $config, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        $value = $config[$key];
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if (is_bool($parsed)) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException(sprintf('Webhook resolved configuration key "%s" must be a boolean.', $key));
    }

    /** @param array<string, mixed> $config */
    private static function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($parsed)) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException(sprintf('Webhook resolved configuration key "%s" must be an integer.', $key));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function section(array $config, string $key): array
    {
        $value = $config[$key] ?? [];
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Webhook resolved configuration section "%s" must be an array.', $key));
        }

        $section = [];
        foreach ($value as $name => $item) {
            if (is_string($name)) {
                $section[$name] = $item;
            }
        }

        return $section;
    }

    /** @param array<string, mixed> $config */
    private static function string(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? $default;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Webhook resolved configuration key "%s" must be a string.', $key));
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('Webhook resolved configuration key "%s" must be non-empty.', $key));
        }

        return $value;
    }
}
