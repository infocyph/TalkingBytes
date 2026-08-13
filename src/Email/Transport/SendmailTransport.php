<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;
use RuntimeException;

final readonly class SendmailTransport implements EmailTransport
{
    private const int MAX_DIAGNOSTIC_BYTES = 65_536;

    public function __construct(
        private SendmailConfig $config = new SendmailConfig(),
        private RawEmailBuilder $rawEmailBuilder = new RawEmailBuilder(),
        private EmailHeaderBuilder $headerBuilder = new EmailHeaderBuilder(),
    ) {}

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

    private function appendDiagnostic(string $buffer, string|false $chunk): string
    {
        if (!is_string($chunk) || $chunk === '' || strlen($buffer) >= self::MAX_DIAGNOSTIC_BYTES) {
            return $buffer;
        }

        return $buffer . substr($chunk, 0, self::MAX_DIAGNOSTIC_BYTES - strlen($buffer));
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
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
    }

    private function executeSendmail(EmailMessage $message): int
    {
        $process = proc_open($this->buildCommand($message), $this->descriptorSpec(), $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to open sendmail process.');
        }

        $processClosed = false;
        $sizeBytes = 0;

        try {
            $sizeBytes = $this->writeToStdin($pipes[0], $message);
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

        return $sizeBytes;
    }

    /**
     * @param resource $process
     * @param resource $stdout
     * @param resource $stderr
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
            $stdoutBuffer = $this->appendDiagnostic($stdoutBuffer, stream_get_contents($stdout));
            $stderrBuffer = $this->appendDiagnostic($stderrBuffer, stream_get_contents($stderr));

            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $start) > $timeoutSeconds) {
                $terminated = true;
                proc_terminate($process);
                usleep(100000);

                $statusAfterGrace = proc_get_status($process);
                if ($statusAfterGrace['running']) {
                    proc_terminate($process, 9);
                }

                break;
            }

            usleep(10000);
        }

        $stdoutBuffer = $this->appendDiagnostic($stdoutBuffer, stream_get_contents($stdout));
        $stderrBuffer = $this->appendDiagnostic($stderrBuffer, stream_get_contents($stderr));

        $exitCode = proc_close($process);

        if ($terminated) {
            throw new RuntimeException(sprintf('Sendmail process timed out after %d seconds.', $timeoutSeconds));
        }

        return [$exitCode, $stdoutBuffer, $stderrBuffer];
    }

    /**
     * @param resource $stdin
     */
    private function writeChunk($stdin, string $chunk): void
    {
        $length = strlen($chunk);
        $written = 0;

        while ($written < $length) {
            $current = fwrite($stdin, substr($chunk, $written));

            if ($current === false || $current === 0) {
                throw new RuntimeException('Unable to write email payload to sendmail process.');
            }

            $written += $current;
        }
    }

    /**
     * @param resource $stdin
     */
    private function writeToStdin($stdin, EmailMessage $message): int
    {
        return $this->rawEmailBuilder->buildToStream(
            $message,
            function (string $chunk) use ($stdin): void {
                $this->writeChunk($stdin, $chunk);
            },
            includeSubject: true,
            maxBytes: $this->config->maxMessageBytes,
        );
    }
}
