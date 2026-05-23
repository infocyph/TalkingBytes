<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use Throwable;

final readonly class RetryEmailTransport implements EmailTransport
{
    public function __construct(
        private EmailTransport $innerTransport,
        private RetryPolicy $retryPolicy,
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $attempt = 1;

        while (true) {
            try {
                $result = $this->innerTransport->send($message);
            } catch (Throwable $throwable) {
                if (!$this->retryPolicy->shouldRetry($attempt, null, $throwable)) {
                    throw $throwable;
                }

                usleep($this->retryPolicy->delayMs($attempt) * 1000);
                $attempt++;

                continue;
            }

            if (!$this->retryPolicy->shouldRetry($attempt, $result)) {
                return $result;
            }

            usleep($this->retryPolicy->delayMs($attempt) * 1000);
            $attempt++;
        }
    }
}
