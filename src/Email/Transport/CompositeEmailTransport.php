<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
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
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $attemptedTransports = [];
        if ($this->cancellationRequested()) {
            return $this->cancelled($attemptedTransports);
        }

        $primaryResult = $this->primaryTransport->send($message);
        $attemptedTransports[] = $this->primaryTransport::class;

        if ($primaryResult->successful) {
            return $primaryResult;
        }

        foreach ($this->fallbackTransports as $transport) {
            if ($this->cancellationRequested()) {
                return $this->cancelled($attemptedTransports);
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
}
