<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\EmailMessage;

final readonly class CompositeEmailTransport implements EmailTransport
{
    /**
     * @param list<EmailTransport> $fallbackTransports
     */
    public function __construct(
        private EmailTransport $primaryTransport,
        private array $fallbackTransports = [],
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $attemptedTransports = [];

        $primaryResult = $this->primaryTransport->send($message);
        $attemptedTransports[] = $this->primaryTransport::class;

        if ($primaryResult->successful) {
            return $primaryResult;
        }

        foreach ($this->fallbackTransports as $transport) {
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
}
