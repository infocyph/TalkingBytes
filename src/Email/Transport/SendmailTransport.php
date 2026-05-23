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

    /**
     * @param array<int, resource|null> $pipes
     */
    private function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (!is_resource($pipe)) {
                continue;
            }

            fclose($pipe);
        }
    }

    /**
     * @param resource $process
     */
    private function closeProcess($process, bool $alreadyClosed): void
    {
        if ($alreadyClosed || !is_resource($process)) {
            return;
        }

        proc_terminate($process);
        proc_close($process);
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function descriptorSpec(): array
    {
        return [
            0 => ['pipe', 'w'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
    }

    private function executeSendmail(EmailMessage $message, string $rawEmail): void
    {
        $process = proc_open($this->buildCommand($message), $this->descriptorSpec(), $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to open sendmail process.');
        }

        $processClosed = false;

        try {
            $this->writeToStdin($pipes[0], $rawEmail);
            fclose($pipes[0]);

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            [$exitCode, $stdout, $stderr] = $this->readProcessOutputUntilExit(
                $process,
                $pipes[1],
                $pipes[2],
                $this->config->timeoutSeconds,
            );
            $processClosed = true;
        } finally {
            $this->closePipes($pipes);
            $this->closeProcess($process, $processClosed);
        }

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

    /**
     * @param resource $process
     * @param resource $stdout
     * @param resource $stderr
     *
     * @return array{0:int,1:string,2:string}
     */
    private function readProcessOutputUntilExit($process, $stdout, $stderr, int $timeoutSeconds): array
    {
        $start = microtime(true);
        $stdoutBuffer = '';
        $stderrBuffer = '';
        $terminated = false;

        while (true) {
            $status = proc_get_status($process);
            $stdoutBuffer .= stream_get_contents($stdout) ?: '';
            $stderrBuffer .= stream_get_contents($stderr) ?: '';

            if ($status['running'] !== true) {
                break;
            }

            if ((microtime(true) - $start) > $timeoutSeconds) {
                $terminated = true;
                proc_terminate($process);
                usleep(100000);

                break;
            }

            usleep(10000);
        }

        $stdoutBuffer .= stream_get_contents($stdout) ?: '';
        $stderrBuffer .= stream_get_contents($stderr) ?: '';

        $exitCode = proc_close($process);

        if ($terminated) {
            throw new RuntimeException(sprintf('Sendmail process timed out after %d seconds.', $timeoutSeconds));
        }

        return [$exitCode, $stdoutBuffer, $stderrBuffer];
    }

    /**
     * @param resource $stdin
     */
    private function writeToStdin($stdin, string $rawEmail): void
    {
        $length = strlen($rawEmail);
        $written = 0;

        while ($written < $length) {
            $chunk = substr($rawEmail, $written);
            $current = fwrite($stdin, $chunk);

            if ($current === false || $current === 0) {
                throw new RuntimeException('Unable to write email payload to sendmail process.');
            }

            $written += $current;
        }
    }
}
