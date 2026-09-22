<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;
use Infocyph\TalkingBytes\Email\System\SendmailProcess;
use RuntimeException;

final readonly class SendmailTransport implements EmailTransport
{
    private Clock $clock;

    private Sleeper $sleeper;

    public function __construct(
        private SendmailConfig $config = new SendmailConfig(),
        private RawEmailBuilder $rawEmailBuilder = new RawEmailBuilder(),
        private EmailHeaderBuilder $headerBuilder = new EmailHeaderBuilder(),
        private ?CancellationSignal $cancellation = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
    ) {
        $this->clock = $clock ?? Clock::system();
        $this->sleeper = $sleeper ?? Sleeper::system();
    }

    public function send(EmailMessage $message): CommunicationResult
    {
        $message = $message->prepare();

        if (!is_executable($this->config->path)) {
            return CommunicationResult::failure(sprintf('Sendmail binary is not executable: %s', $this->config->path));
        }

        $recipients = array_map(static fn($address): string => $address->email, $message->envelope()->recipients());
        $messageId = $this->headerBuilder->resolveMessageId($message);

        try {
            $sizeBytes = $this->executeSendmail($message);
        } catch (RuntimeException $exception) {
            return EmailTransportResultFactory::failure(
                'sendmail',
                $messageId,
                $exception->getMessage(),
                $recipients,
            );
        }

        return EmailTransportResultFactory::success('sendmail', $messageId, $recipients, ['size_bytes' => $sizeBytes]);
    }

    /**
     * @return list<string>
     */
    private function buildCommand(EmailMessage $message): array
    {
        $command = [$this->config->path, ...$this->config->extraArguments];

        $sender = $message->envelope()->envelopeSender();
        if ($sender !== null) {
            $command[] = sprintf('-f%s', $sender->email);
        }

        return $command;
    }

    private function executeSendmail(EmailMessage $message): int
    {
        $process = SendmailProcess::start(
            $this->buildCommand($message),
            $this->config->timeoutSeconds,
            $this->cancellation,
            $this->clock,
            $this->sleeper,
        );

        try {
            $sizeBytes = $this->rawEmailBuilder->buildToStream(
                $message,
                $process->write(...),
                includeSubject: true,
                maxBytes: $this->config->maxMessageBytes,
            );
            $result = $process->finish();
        } finally {
            $process->close();
        }

        if ($result->exitCode !== 0) {
            $detail = trim(($result->stderr ?: $result->stdout) ?: 'unknown error');

            throw new RuntimeException(sprintf(
                'Sendmail exited with code %d: %s',
                $result->exitCode,
                $detail,
            ));
        }

        return $sizeBytes;
    }
}
