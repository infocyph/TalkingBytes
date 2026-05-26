<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Resilience\RateLimiter;

final readonly class RateLimitedEmailTransport implements EmailTransport
{
    public function __construct(
        private EmailTransport $innerTransport,
        private RateLimiter $rateLimiter,
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $this->rateLimiter->assertCanProceed();

        return $this->innerTransport->send($message);
    }
}
