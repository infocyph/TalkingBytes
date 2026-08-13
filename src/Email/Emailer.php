<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
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
    private EventDispatcher $events;

    public function __construct(private EmailTransport $transport, ?EventDispatcher $events = null)
    {
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
    }

    public static function fake(): self
    {
        return new self(new FakeEmailTransport());
    }

    public static function usingLog(LogEmailConfig $config): self
    {
        return new self(new LogEmailTransport($config));
    }

    public static function usingMailFunction(): self
    {
        return new self(new MailFunctionTransport());
    }

    public static function usingNull(): self
    {
        return new self(new NullEmailTransport());
    }

    public static function usingSendmail(SendmailConfig $config = new SendmailConfig()): self
    {
        return new self(new SendmailTransport($config));
    }

    public static function usingSmtp(SmtpConfig $config): self
    {
        return new self(new SmtpTransport($config));
    }

    public static function usingSpool(SpoolConfig $config): self
    {
        return new self(new SpoolEmailTransport($config));
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
            'subject' => $message->headersData()->subject,
            'to_count' => count($message->envelope()->to),
            'cc_count' => count($message->envelope()->cc),
            'bcc_count' => count($message->envelope()->bcc),
        ]);

        $startedAt = microtime(true);
        $result = $this->transport->send($message->prepare());

        $this->events->dispatch('email.send.finish', [
            'successful' => $result->successful,
            'error' => $result->error,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
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
        return new self(new DkimSigningTransport($this->transport, $config), $this->events);
    }

    /**
     * @param list<EmailTransport> $fallbackTransports
     */
    public function withFallback(array $fallbackTransports): self
    {
        return new self(new FallbackEmailTransport($this->transport, $fallbackTransports), $this->events);
    }

    /**
     * @param callable(string, array<string,mixed>):void $logger
     */
    public function withLogging(callable $logger): self
    {
        return new self(new LoggingEmailTransport($this->transport, $logger), $this->events);
    }

    public function withPsrLogger(object $logger, string $level = 'info'): self
    {
        return $this->withLogging(new Psr3LoggerAdapter($logger)->toCallable($level));
    }

    public function withRateLimit(RateLimiter $rateLimiter): self
    {
        return new self(new RateLimitedEmailTransport($this->transport, $rateLimiter), $this->events);
    }

    public function withRetry(RetryPolicy $retryPolicy): self
    {
        return new self(new RetryEmailTransport($this->transport, $retryPolicy), $this->events);
    }

    public function withTransport(EmailTransport $transport): self
    {
        return new self($transport, $this->events);
    }
}
