<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Email\EmailMessage;

final readonly class CompositeEmailTransport implements EmailTransport
{
    /** @phpstan-impure */
    private function cancellationRequested(): bool
    {
        return $this->cancellation !== null && $this->cancellation->isRequested();
    }

    /** @param list<class-string<EmailTransport>> $attemptedTransports */
    private function cancelled(array $attemptedTransports): CommunicationResult
    {
        return CommunicationResult::failure(
            'Email operation cancelled.',
            metadata: [
                'cancelled' => true,
                'attempts' => count($attemptedTransports),
                'attempted_transports' => $attemptedTransports,
                'transport' => 'email',
            ],
        );
    }

    /** @param list<class-string<EmailTransport>> $attemptedTransports */
    private function deadlineExceeded(array $attemptedTransports): CommunicationResult
    {
        return CommunicationResult::failure(
            'Email operation deadline exceeded.',
            metadata: [
                'deadline_exceeded' => true,
                'attempts' => count($attemptedTransports),
                'attempted_transports' => $attemptedTransports,
                'transport' => 'email',
            ],
        );
    }

    /**
     * @param list<class-string<EmailTransport>> $attemptedTransports
     */
    private function preflightFailure(array $attemptedTransports): ?CommunicationResult
    {
        if ($this->cancellationRequested()) {
            return $this->cancelled($attemptedTransports);
        }

        if ($this->deadline?->expired() === true) {
            return $this->deadlineExceeded($attemptedTransports);
        }

        return null;
    }

    /**
     * @param list<EmailTransport> $fallbackTransports
     */
    public function __construct(
        private EmailTransport $primaryTransport,
        private array $fallbackTransports = [],
        private ?CancellationSignal $cancellation = null,
        private ?OperationDeadline $deadline = null,
    ) {}
}
