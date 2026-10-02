<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Core\Support\ResolvedConfig;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Core\Support\StreamWaiter;
use Infocyph\TalkingBytes\Email\Config\ConfigValue;
use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Integration\Runwire\RunwireBinding;
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
        private ?CancellationSignal $cancellation = null,
        private ?OperationDeadline $operationDeadline = null,
        private ?StreamWaiter $streamWaiter = null,
    ) {
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
        $this->sleeper = $sleeper ?? Sleeper::system();
    }

    public function fake(): Emailer
    {
        return $this->bindExecution(
            Emailer::fake($this->events, $this->clock, $this->sleeper),
        );
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
        $cancellation = $this->combinedCancellation($cancellation);
        $transport = ResolvedConfig::section($config, 'transport', 'Email', required: true);
        $emailer = $this->usingResolvedTransport($transport, $cancellation);

        $fallbackTransports = [];
        foreach (ResolvedConfig::sections($config, 'fallbacks', 'Email') as $fallback) {
            $fallbackTransports[] = $this->usingResolvedTransport($fallback, $cancellation)->transport();
        }
        if ($fallbackTransports !== []) {
            $emailer = $emailer->withFallback(
                $fallbackTransports,
                $cancellation,
                $this->operationDeadline,
            );
        }

        $retry = ResolvedConfig::section($config, 'retry', 'Email');
        if (ConfigValue::bool($retry, 'enabled', false)) {
            $attempts = ConfigValue::int($retry, 'max_attempts', 3);
            $delayMs = ConfigValue::int($retry, 'delay_ms', 250);
            $policy = match (ConfigValue::string($retry, 'policy', 'fixed')) {
                'backoff', 'exponential' => new ExponentialBackoffRetryPolicy($attempts, $delayMs),
                'fixed' => new FixedDelayRetryPolicy($attempts, $delayMs),
                default => throw new InvalidArgumentException('Unsupported email retry policy.'),
            };
            $emailer = $emailer->withRetry($policy, $cancellation, $this->operationDeadline);
        }

        $rateLimit = ResolvedConfig::section($config, 'rate_limit', 'Email');
        if (ConfigValue::bool($rateLimit, 'enabled', false)) {
            $emailer = $emailer->withRateLimit(new RateLimiter(
                ConfigValue::int($rateLimit, 'max_requests', 60),
                ConfigValue::int($rateLimit, 'per_seconds', 60),
                $this->clock,
            ));
        }

        $dkim = ResolvedConfig::section($config, 'dkim', 'Email');
        if (ConfigValue::bool($dkim, 'enabled', false)) {
            $emailer = $emailer->withDkim(DkimConfig::fromArray($dkim));
        }

        return $this->bindExecution($emailer, $cancellation);
    }

    public function usingLog(LogEmailConfig $config): Emailer
    {
        return $this->bindExecution(
            Emailer::usingLog($config, $this->events, $this->clock, $this->sleeper),
        );
    }

    public function usingMailFunction(): Emailer
    {
        return $this->bindExecution(
            Emailer::usingMailFunction($this->events, $this->clock, $this->sleeper),
        );
    }

    public function usingNull(): Emailer
    {
        return $this->bindExecution(
            Emailer::usingNull($this->events, $this->clock, $this->sleeper),
        );
    }

    public function usingSendmail(
        SendmailConfig $config = new SendmailConfig(),
        ?CancellationSignal $cancellation = null,
    ): Emailer {
        $cancellation = $this->combinedCancellation($cancellation);

        return $this->bindExecution(
            Emailer::usingSendmail(
                $config,
                $this->events,
                $this->clock,
                $cancellation,
                $this->sleeper,
                $this->operationDeadline,
            ),
            $cancellation,
        );
    }

    public function usingSmtp(SmtpConfig $config): Emailer
    {
        $cancellation = $this->combinedCancellation(null);

        return $this->bindExecution(
            Emailer::usingSmtp(
                $config,
                $this->events,
                $this->clock,
                $this->sleeper,
                $cancellation,
                $this->operationDeadline,
                $this->streamWaiter,
            ),
            $cancellation,
        );
    }

    public function usingSpool(SpoolConfig $config): Emailer
    {
        return $this->bindExecution(
            Emailer::usingSpool($config, $this->events, $this->clock, $this->sleeper),
        );
    }

    public function withRunwire(
        RuntimeContext $runtime,
        ?RequestContext $request = null,
        ?CoroutineScope $scope = null,
    ): self {
        $binding = new RunwireBinding($runtime, $request, $scope);
        $deadline = $binding->deadline();
        if ($deadline !== null && $this->operationDeadline !== null) {
            $deadline = $this->operationDeadline->earliest($deadline);
        } elseif ($deadline === null) {
            $deadline = $this->operationDeadline;
        }

        return new self(
            $this->events,
            $this->clock,
            $binding->sleeper($this->sleeper),
            $binding->cancellation($this->cancellation),
            $deadline,
            $this->streamWaiter ?? $binding->streamWaiter(),
        );
    }

    private function bindExecution(
        Emailer $emailer,
        ?CancellationSignal $cancellation = null,
    ): Emailer {
        if ($this->operationDeadline !== null) {
            $emailer = $emailer->withOperationDeadline($this->operationDeadline);
        }

        $cancellation = $this->combinedCancellation($cancellation);
        if ($cancellation !== null) {
            $emailer = $emailer->withCancellation($cancellation);
        }

        return $emailer;
    }

    private function combinedCancellation(?CancellationSignal $explicit): ?CancellationSignal
    {
        if ($this->cancellation === null) {
            return $explicit;
        }

        if ($explicit === null || $explicit === $this->cancellation) {
            return $this->cancellation;
        }

        $factory = $this->cancellation;

        return CancellationSignal::fromCallable(
            static fn(): bool => $factory->isRequested() || $explicit->isRequested(),
        );
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
