<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use RuntimeException;
use Throwable;

final class SendmailProcess
{
    private const int FORCE_SIGNAL = 9;

    private const int GRACEFUL_SIGNAL = 15;

    private const int MAX_DIAGNOSTIC_BYTES = 65_536;

    private const int POLL_DELAY_MS = 10;

    private const int TERMINATION_GRACE_MS = 100;

    private string $stderr = '';

    private string $stdout = '';

    /**
     * @param resource $process
     * @param array<int, resource|null> $pipes
     */
    private function __construct(
        private mixed $process,
        private array $pipes,
        private readonly float $deadline,
        private readonly int $timeoutSeconds,
        private readonly Clock $clock,
        private readonly Sleeper $sleeper,
        private readonly ?CancellationSignal $cancellation,
        private readonly ?int $processGroupId,
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param list<string> $command
     */
    public static function start(
        array $command,
        int $timeoutSeconds,
        ?CancellationSignal $cancellation = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
    ): self {
        $runtimeClock = $clock ?? Clock::system();
        $runtimeSleeper = $sleeper ?? Sleeper::system();
        if ($cancellation?->isRequested() === true) {
            throw new RuntimeException('Sendmail process cancelled.');
        }

        $process = proc_open($command, self::descriptorSpec(), $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to open sendmail process.');
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe) && !stream_set_blocking($pipe, false)) {
                self::closePipeSet($pipes);
                proc_terminate($process);
                proc_close($process);

                throw new RuntimeException('Unable to configure sendmail process pipes.');
            }
        }

        return new self(
            $process,
            $pipes,
            $runtimeClock->monotonic() + $timeoutSeconds,
            $timeoutSeconds,
            $runtimeClock,
            $runtimeSleeper,
            $cancellation,
            self::tryCreateProcessGroup($process),
        );
    }

    public function close(): void
    {
        self::closePipeSet($this->pipes);
        $this->pipes = [];

        if (!is_resource($this->process)) {
            return;
        }

        $status = proc_get_status($this->process);
        if (($status['running'] ?? false) === true) {
            $this->terminate();
        }

        proc_close($this->process);
        $this->process = null;
    }

    public function finish(): SendmailProcessResult
    {
        $this->closeStdin();

        while (true) {
            $this->assertActive();
            $this->drainOutput();

            $status = proc_get_status($this->requireProcess());
            if (($status['running'] ?? false) !== true) {
                $this->drainOutput();

                $exitCode = is_int($status['exitcode'] ?? null) ? $status['exitcode'] : -1;
                $closeCode = proc_close($this->requireProcess());
                $this->process = null;
                self::closePipeSet($this->pipes);
                $this->pipes = [];

                if ($closeCode >= 0) {
                    $exitCode = $closeCode;
                }

                return new SendmailProcessResult($exitCode, $this->stdout, $this->stderr);
            }

            $this->sleeper->milliseconds(self::POLL_DELAY_MS);
        }
    }

    public function write(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $stdin = $this->pipe(0);
        $length = strlen($chunk);
        $written = 0;

        while ($written < $length) {
            $this->assertActive();
            $this->drainOutput();

            $current = fwrite($stdin, substr($chunk, $written));
            if ($current === false) {
                throw new RuntimeException('Unable to write email payload to sendmail process.');
            }

            if ($current === 0) {
                $status = proc_get_status($this->requireProcess());
                if (($status['running'] ?? false) !== true) {
                    throw new RuntimeException('Sendmail process exited while receiving the email payload.');
                }

                $this->sleeper->milliseconds(self::POLL_DELAY_MS);

                continue;
            }

            $written += $current;
        }
    }

    private function appendDiagnostic(string $buffer, string|false $chunk): string
    {
        if (!is_string($chunk) || $chunk === '' || strlen($buffer) >= self::MAX_DIAGNOSTIC_BYTES) {
            return $buffer;
        }

        return $buffer . substr($chunk, 0, self::MAX_DIAGNOSTIC_BYTES - strlen($buffer));
    }

    private function assertActive(): void
    {
        if ($this->cancellation?->isRequested() === true) {
            $this->terminate();

            throw new RuntimeException('Sendmail process cancelled.');
        }

        if ($this->clock->monotonic() >= $this->deadline) {
            $this->terminate();

            throw new RuntimeException(sprintf(
                'Sendmail process timed out after %d seconds.',
                $this->timeoutSeconds,
            ));
        }
    }

    private function closeStdin(): void
    {
        $stdin = $this->pipes[0] ?? null;
        if (is_resource($stdin)) {
            fclose($stdin);
        }

        $this->pipes[0] = null;
    }

    /**
     * @param array<int, resource|null> $pipes
     */
    private static function closePipeSet(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private static function descriptorSpec(): array
    {
        return [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
    }

    private function drainOutput(): void
    {
        $stdout = $this->pipes[1] ?? null;
        if (is_resource($stdout)) {
            $this->stdout = $this->appendDiagnostic($this->stdout, stream_get_contents($stdout));
        }

        $stderr = $this->pipes[2] ?? null;
        if (is_resource($stderr)) {
            $this->stderr = $this->appendDiagnostic($this->stderr, stream_get_contents($stderr));
        }
    }

    /**
     * @return resource
     */
    private function pipe(int $index)
    {
        $pipe = $this->pipes[$index] ?? null;
        if (!is_resource($pipe)) {
            throw new RuntimeException('Sendmail process pipe is not available.');
        }

        return $pipe;
    }

    /**
     * @return resource
     */
    private function requireProcess()
    {
        if (!is_resource($this->process)) {
            throw new RuntimeException('Sendmail process is not available.');
        }

        return $this->process;
    }

    private function signal(int $signal): void
    {
        if (
            $this->processGroupId !== null
            && function_exists('posix_kill')
        ) {
            try {
                if (posix_kill(-$this->processGroupId, $signal)) {
                    return;
                }
            } catch (Throwable) {
                // Fall through to portable direct-child termination.
            }
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process, $signal);
        }
    }

    private function terminate(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        $status = proc_get_status($this->process);
        if (($status['running'] ?? false) !== true) {
            return;
        }

        $this->signal(self::GRACEFUL_SIGNAL);
        $this->sleeper->milliseconds(self::TERMINATION_GRACE_MS);

        $status = proc_get_status($this->process);
        if (($status['running'] ?? false) === true) {
            $this->signal(self::FORCE_SIGNAL);
        }
    }

    /**
     * @param resource $process
     */
    private static function tryCreateProcessGroup($process): ?int
    {
        if (
            !function_exists('posix_setpgid')
            || !function_exists('posix_getpgid')
            || !function_exists('posix_kill')
        ) {
            return null;
        }

        $status = proc_get_status($process);
        $pid = is_int($status['pid'] ?? null) ? $status['pid'] : 0;
        if ($pid < 1) {
            return null;
        }

        try {
            if (!posix_setpgid($pid, $pid)) {
                return null;
            }

            return posix_getpgid($pid) === $pid ? $pid : null;
        } catch (Throwable) {
            return null;
        }
    }
}
