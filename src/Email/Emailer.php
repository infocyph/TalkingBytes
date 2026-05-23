<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
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
    public function __construct(private EmailTransport $transport) {}

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
        return $this->transport->send($message);
    }

    public function transport(): EmailTransport
    {
        return $this->transport;
    }

    public function withDkim(DkimConfig $config): self
    {
        return new self(new DkimSigningTransport($this->transport, $config));
    }

    /**
     * @param list<EmailTransport> $fallbackTransports
     */
    public function withFallback(array $fallbackTransports): self
    {
        return new self(new FallbackEmailTransport($this->transport, $fallbackTransports));
    }

    /**
     * @param callable(string, array<string,mixed>):void $logger
     */
    public function withLogging(callable $logger): self
    {
        return new self(new LoggingEmailTransport($this->transport, $logger));
    }

    public function withRateLimit(RateLimiter $rateLimiter): self
    {
        return new self(new RateLimitedEmailTransport($this->transport, $rateLimiter));
    }

    public function withRetry(RetryPolicy $retryPolicy): self
    {
        return new self(new RetryEmailTransport($this->transport, $retryPolicy));
    }

    public function withTransport(EmailTransport $transport): self
    {
        return new self($transport);
    }
}
