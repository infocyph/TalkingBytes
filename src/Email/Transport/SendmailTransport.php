<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Result\EmailSendResult;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;
use RuntimeException;

final readonly class SendmailTransport implements EmailTransport
{
    public function __construct(
        private SendmailConfig $config = new SendmailConfig(),
        private RawEmailBuilder $rawEmailBuilder = new RawEmailBuilder(),
        private EmailHeaderBuilder $headerBuilder = new EmailHeaderBuilder(),
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $message->assertReadyToSend();

        if (!is_executable($this->config->path)) {
            return CommunicationResult::failure(sprintf('Sendmail binary is not executable: %s', $this->config->path));
        }

        $rawEmail = $this->rawEmailBuilder->build($message, includeSubject: true);
        $recipients = array_map(static fn($address): string => $address->email, $message->envelope()->recipients());
        $messageId = $this->extractMessageId($rawEmail->headers) ?? $this->headerBuilder->resolveMessageId($message);

        try {
            $this->executeSendmail($message, $rawEmail->raw);
        } catch (RuntimeException $exception) {
            $result = new EmailSendResult(
                'sendmail',
                $messageId,
                [],
                array_fill_keys($recipients, $exception->getMessage()),
                ['transport' => 'sendmail', 'size_bytes' => $rawEmail->sizeBytes],
            );

            return CommunicationResult::failure(
                $exception->getMessage(),
                response: $result,
                metadata: $result->metadata,
            );
        }

        $result = new EmailSendResult(
            'sendmail',
            $messageId,
            $recipients,
            [],
            ['transport' => 'sendmail', 'size_bytes' => $rawEmail->sizeBytes],
        );

        return CommunicationResult::success(response: $result, metadata: $result->metadata);
    }

    private function executeSendmail(EmailMessage $message, string $rawEmail): void
    {
        $command = [$this->config->path, ...$this->config->extraArguments];

        $sender = $message->envelope()->envelopeSender();
        if ($sender !== null) {
            $command[] = sprintf('-f%s', $sender->email);
        }

        $descriptorSpec = [
            0 => ['pipe', 'w'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to open sendmail process.');
        }

        fwrite($pipes[0], $rawEmail);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $detail = trim(($stderr ?: $stdout) ?: 'unknown error');

            throw new RuntimeException(sprintf('Sendmail exited with code %d: %s', $exitCode, $detail));
        }
    }

    private function extractMessageId(string $headers): ?string
    {
        if (preg_match('/^Message-ID:\s*(.+)$/mi', $headers, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }
}
