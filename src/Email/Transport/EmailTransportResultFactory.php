<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Result\EmailSendResult;

final class EmailTransportResultFactory
{
    /**
     * @param list<string> $recipients
     * @param array<string, mixed> $metadata
     */
    public static function failure(
        string $transport,
        ?string $messageId,
        string $error,
        array $recipients,
        array $metadata = [],
    ): CommunicationResult {
        $result = new EmailSendResult(
            $transport,
            self::normalizeMessageId($messageId),
            [],
            array_fill_keys($recipients, $error),
            $metadata + ['transport' => $transport],
        );

        return CommunicationResult::failure($error, response: $result, metadata: $result->metadata);
    }

    /**
     * @param list<string> $acceptedRecipients
     * @param array<string, mixed> $metadata
     */
    public static function success(
        string $transport,
        ?string $messageId,
        array $acceptedRecipients,
        array $metadata = [],
    ): CommunicationResult {
        $result = new EmailSendResult($transport, self::normalizeMessageId($messageId), $acceptedRecipients, [], $metadata + ['transport' => $transport]);

        return CommunicationResult::success(response: $result, metadata: $result->metadata);
    }

    private static function normalizeMessageId(?string $messageId): string
    {
        $trimmed = trim((string) $messageId);
        if ($trimmed !== '') {
            return $trimmed;
        }

        return sprintf('<%s@talkingbytes.local>', bin2hex(random_bytes(16)));
    }
}
