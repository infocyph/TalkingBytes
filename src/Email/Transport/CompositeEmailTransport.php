<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Email\EmailMessage;

final readonly class CompositeEmailTransport implements EmailTransport
{
    /**
     * @param list<EmailTransport> $fallbackTransports
     */
    public function __construct(
        private EmailTransport $primaryTransport,
        private array $fallbackTransports = [],
        private ?CancellationSignal $cancellation = null,
        private ?OperationDeadline $deadline = null,
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $attemptedTransports = [];
        $preflight = $this->preflightFailure($attemptedTransports);
        if ($preflight !== null) {
            return $preflight;
        }

        $primaryResult = $this->primaryTransport->send($message);
        $attemptedTransports[] = $this->primaryTransport::class;

        if ($primaryResult->successful) {
            return $primaryResult;
        }
        if ($this->isTerminalExecutionFailure($primaryResult)) {
            return $this->terminalFailure($primaryResult, $attemptedTransports);
        }

        foreach ($this->fallbackTransports as $transport) {
            $preflight = $this->preflightFailure($attemptedTransports);
            if ($preflight !== null) {
                return $preflight;
            }

            $attemptedTransports[] = $transport::class;
            $result = $transport->send($message);

            if ($result->successful) {
                $metadata = $result->metadata;
                $metadata['fallback_used'] = true;
                $metadata['attempted_transports'] = $attemptedTransports;

                return new CommunicationResult(
                    true,
                    $result->statusCode,
                    null,
                    $result->response,
                    $metadata,
                );
            }
            if ($this->isTerminalExecutionFailure($result)) {
                return $this->terminalFailure($result, $attemptedTransports);
            }

            $primaryResult = $result;
        }

        $metadata = $primaryResult->metadata;
        $metadata['attempted_transports'] = $attemptedTransports;

        return CommunicationResult::failure(
            $primaryResult->error ?? 'All configured transports failed.',
            statusCode: $primaryResult->statusCode,
            response: $primaryResult->response,
            metadata: $metadata,
        );
    }

    private function isTerminalExecutionFailure(CommunicationResult $result): bool
    {
        return ($result->metadata['cancelled'] ?? false) === true
            || ($result->metadata['deadline_exceeded'] ?? false) === true;
    }

    /**
     * @param list<class-string<EmailTransport>> $attemptedTransports
     */
    private function terminalFailure(
        CommunicationResult $result,
        array $attemptedTransports,
    ): CommunicationResult {
        return CommunicationResult::failure(
            $result->error ?? 'Email operation terminated.',
            statusCode: $result->statusCode,
            response: $result->response,
            metadata: [
                ...$result->metadata,
                'attempted_transports' => $attemptedTransports,
            ],
        );
    }

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
}
