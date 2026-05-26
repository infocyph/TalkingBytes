<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Result\EmailSendResult;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;

final readonly class NullEmailTransport implements EmailTransport
{
    public function __construct(private EmailHeaderBuilder $headerBuilder = new EmailHeaderBuilder()) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $message->assertReadyToSend();

        $recipients = array_map(static fn($address): string => $address->email, $message->envelope()->recipients());
        $result = new EmailSendResult(
            'null-email',
            $this->headerBuilder->resolveMessageId($message),
            $recipients,
            [],
            [
                'transport' => 'null-email',
                'recipient_count' => count($recipients),
            ],
        );

        return CommunicationResult::success(response: $result, metadata: $result->metadata);
    }
}
