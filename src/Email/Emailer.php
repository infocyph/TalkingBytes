<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\ObservabilitySanitizer;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Logging\Psr3LoggerAdapter;
use Infocyph\TalkingBytes\Email\Testing\AssertableEmailTransport;
use Infocyph\TalkingBytes\Email\Testing\FakeEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\DkimSigningTransport;
use Infocyph\TalkingBytes\Email\Transport\EmailTransport;
use Infocyph\TalkingBytes\Email\Transport\FallbackEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\LogEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\LoggingEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\MailFunctionTransport;
use Infocyph\TalkingBytes\Email\Transport\NullEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\RateLimitedEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\RetryEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\SendmailTransport;
use Infocyph\TalkingBytes\Email\Transport\SmtpTransport;
use Infocyph\TalkingBytes\Email\Transport\SpoolEmailTransport;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use Infocyph\TalkingBytes\Retry\RetryPolicy;

final readonly class Emailer
{
    private Clock $clock;

    private EventDispatcher $events;

    public function __construct(
        private EmailTransport $transport,
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
    ) {
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
    }

    public static function fake(?EventDispatcher $events = null, ?Clock $clock = null): self
    {
        return new self(new FakeEmailTransport(), $events, $clock);
    }

    public static function usingLog(LogEmailConfig $config, ?EventDispatcher $events = null, ?Clock $clock = null): self
    {
        return new self(new LogEmailTransport($config), $events, $clock);
    }

    public static function usingMailFunction(?EventDispatcher $events = null, ?Clock $clock = null): self
    {
        return new self(new MailFunctionTransport(), $events, $clock);
    }

    public static function usingNull(?EventDispatcher $events = null, ?Clock $clock = null): self
    {
        return new self(new NullEmailTransport(), $events, $clock);
    }

    public static function usingSendmail(
        SendmailConfig $config = new SendmailConfig(),
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        ?CancellationSignal $cancellation = null,
        ?Sleeper $sleeper = null,
    ): self {
        return new self(
            new SendmailTransport(
                $config,
                cancellation: $cancellation,
                clock: $clock,
                sleeper: $sleeper,
            ),
            $events,
            $clock,
        );
    }

    public static function usingSmtp(SmtpConfig $config, ?EventDispatcher $events = null, ?Clock $clock = null): self
    {
        return new self(new SmtpTransport($config, clock: $clock), $events, $clock);
    }

    public static function usingSpool(SpoolConfig $config, ?EventDispatcher $events = null, ?Clock $clock = null): self
    {
        return new self(new SpoolEmailTransport($config), $events, $clock);
    }

    public function assertable(): AssertableEmailTransport
    {
        if (!$this->transport instanceof FakeEmailTransport) {
            throw new \LogicException('Assertable email transport is available only when using Emailer::fake().');
        }

        return new AssertableEmailTransport($this->transport);
    }

    public function send(EmailMessage $message): CommunicationResult
    {
        $this->events->dispatch('email.send.start', [
            'to_count' => count($message->envelope()->to),
            'cc_count' => count($message->envelope()->cc),
            'bcc_count' => count($message->envelope()->bcc),
        ]);

        $startedAt = $this->clock->monotonic();
        $result = $this->transport->send($message->prepare());

        $this->events->dispatch('email.send.finish', [
            'successful' => $result->successful,
            'failure_category' => $result->successful ? null : (ObservabilitySanitizer::resultContext($result)['failure_category'] ?? 'transport_error'),
            'duration_ms' => (int) round(($this->clock->monotonic() - $startedAt) * 1000),
            'transport' => is_string($result->metadata['transport'] ?? null)
                ? $result->metadata['transport']
                : null,
            'status_code' => $result->statusCode,
        ]);

        return $result;
    }

    public function transport(): EmailTransport
    {
        return $this->transport;
    }

    public function withDkim(DkimConfig $config): self
    {
        return new self(new DkimSigningTransport($this->transport, $config), $this->events, $this->clock);
    }

    /**
     * @param list<EmailTransport> $fallbackTransports
     */
    public function withFallback(array $fallbackTransports): self
    {
        return new self(new FallbackEmailTransport($this->transport, $fallbackTransports), $this->events, $this->clock);
    }

    /**
     * @param callable(string, array<string,mixed>):void $logger
     */
    public function withLogging(callable $logger): self
    {
        return new self(new LoggingEmailTransport($this->transport, $logger), $this->events, $this->clock);
    }

    public function withPsrLogger(object $logger, string $level = 'info'): self
    {
        return $this->withLogging(new Psr3LoggerAdapter($logger)->toCallable($level));
    }

    public function withRateLimit(RateLimiter $rateLimiter): self
    {
        return new self(new RateLimitedEmailTransport($this->transport, $rateLimiter), $this->events, $this->clock);
    }

    public function withRetry(RetryPolicy $retryPolicy, ?CancellationSignal $cancellation = null): self
    {
        return new self(
            new RetryEmailTransport($this->transport, $retryPolicy, $cancellation),
            $this->events,
            $this->clock,
        );
    }

    public function withTransport(EmailTransport $transport): self
    {
        return new self($transport, $this->events, $this->clock);
    }
}
