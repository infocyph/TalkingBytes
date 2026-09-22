<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Email\Config\ConfigValue;
use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use Infocyph\TalkingBytes\Retry\ExponentialBackoffRetryPolicy;
use Infocyph\TalkingBytes\Retry\FixedDelayRetryPolicy;
use InvalidArgumentException;

final readonly class EmailSenderFactory
{
    private Clock $clock;

    private EventDispatcher $events;

    private Sleeper $sleeper;

    public function __construct(
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
    ) {
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
        $this->sleeper = $sleeper ?? Sleeper::system();
    }

    public function fake(): Emailer
    {
        return Emailer::fake($this->events, $this->clock);
    }

    /**
     * Build an email sender from already-resolved protocol configuration.
     *
     * Expected top-level sections are transport, fallbacks, retry, rate_limit and dkim.
     * Paths and secrets must already reflect host policy before reaching this boundary.
     *
     * @param array<string, mixed> $config
     */
    public function fromResolvedConfig(
        array $config,
        ?CancellationSignal $cancellation = null,
    ): Emailer {
        $transport = self::section($config, 'transport', required: true);
        $emailer = $this->usingResolvedTransport($transport, $cancellation);

        $fallbackTransports = [];
        foreach (self::sections($config, 'fallbacks') as $fallback) {
            $fallbackTransports[] = $this->usingResolvedTransport($fallback, $cancellation)->transport();
        }
        if ($fallbackTransports !== []) {
            $emailer = $emailer->withFallback($fallbackTransports);
        }

        $retry = self::section($config, 'retry');
        if (ConfigValue::bool($retry, 'enabled', false)) {
            $attempts = ConfigValue::int($retry, 'max_attempts', 3);
            $delayMs = ConfigValue::int($retry, 'delay_ms', 250);
            $policy = match (ConfigValue::string($retry, 'policy', 'fixed')) {
                'backoff', 'exponential' => new ExponentialBackoffRetryPolicy($attempts, $delayMs),
                'fixed' => new FixedDelayRetryPolicy($attempts, $delayMs),
                default => throw new InvalidArgumentException('Unsupported email retry policy.'),
            };
            $emailer = $emailer->withRetry($policy, $cancellation);
        }

        $rateLimit = self::section($config, 'rate_limit');
        if (ConfigValue::bool($rateLimit, 'enabled', false)) {
            $emailer = $emailer->withRateLimit(new RateLimiter(
                ConfigValue::int($rateLimit, 'max_requests', 60),
                ConfigValue::int($rateLimit, 'per_seconds', 60),
            ));
        }

        $dkim = self::section($config, 'dkim');
        if (ConfigValue::bool($dkim, 'enabled', false)) {
            $emailer = $emailer->withDkim(DkimConfig::fromArray($dkim));
        }

        return $emailer;
    }

    public function usingLog(LogEmailConfig $config): Emailer
    {
        return Emailer::usingLog($config, $this->events, $this->clock);
    }

    public function usingMailFunction(): Emailer
    {
        return Emailer::usingMailFunction($this->events, $this->clock);
    }

    public function usingNull(): Emailer
    {
        return Emailer::usingNull($this->events, $this->clock);
    }

    public function usingSendmail(
        SendmailConfig $config = new SendmailConfig(),
        ?CancellationSignal $cancellation = null,
    ): Emailer {
        return Emailer::usingSendmail(
            $config,
            $this->events,
            $this->clock,
            $cancellation,
            $this->sleeper,
        );
    }

    public function usingSmtp(SmtpConfig $config): Emailer
    {
        return Emailer::usingSmtp($config, $this->events, $this->clock);
    }

    public function usingSpool(SpoolConfig $config): Emailer
    {
        return Emailer::usingSpool($config, $this->events, $this->clock);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function section(array $config, string $key, bool $required = false): array
    {
        $value = $config[$key] ?? null;
        if ($value === null && !$required) {
            return [];
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Email resolved configuration section "%s" must be an array.', $key));
        }

        $section = [];
        foreach ($value as $name => $item) {
            if (is_string($name)) {
                $section[$name] = $item;
            }
        }

        if ($required && $section === []) {
            throw new InvalidArgumentException(sprintf('Email resolved configuration section "%s" must not be empty.', $key));
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private static function sections(array $config, string $key): array
    {
        $value = $config[$key] ?? [];
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Email resolved configuration section "%s" must be a list.', $key));
        }

        $sections = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException(sprintf('Email resolved configuration section "%s" must contain arrays.', $key));
            }

            $section = [];
            foreach ($item as $name => $entry) {
                if (is_string($name)) {
                    $section[$name] = $entry;
                }
            }
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function usingResolvedTransport(
        array $config,
        ?CancellationSignal $cancellation,
    ): Emailer {
        $driver = trim(ConfigValue::string($config, 'driver', ''));
        if ($driver === '') {
            throw new InvalidArgumentException('Resolved email transport driver must be non-empty.');
        }

        return match ($driver) {
            'fake' => $this->fake(),
            'log' => $this->usingLog(LogEmailConfig::fromArray($config)),
            'mail' => $this->usingMailFunction(),
            'null' => $this->usingNull(),
            'sendmail' => $this->usingSendmail(SendmailConfig::fromArray($config), $cancellation),
            'smtp' => $this->usingSmtp(SmtpConfig::fromArray($config)),
            'spool' => $this->usingSpool(SpoolConfig::fromArray($config)),
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported resolved email transport driver "%s".',
                $driver,
            )),
        };
    }
}
