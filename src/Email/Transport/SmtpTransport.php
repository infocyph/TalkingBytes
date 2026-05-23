<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\SmtpAuthMechanism;
use Infocyph\TalkingBytes\Email\Enum\SmtpSecurity;
use Infocyph\TalkingBytes\Email\Result\EmailDeliveryReport;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;
use Infocyph\TalkingBytes\Email\System\SmtpCapabilities;
use Infocyph\TalkingBytes\Email\System\SmtpCapabilityParser;
use Infocyph\TalkingBytes\Email\ValueObject\EmailHeaders;
use RuntimeException;

final readonly class SmtpTransport implements EmailTransport
{
    public function __construct(
        private SmtpConfig $config,
        private RawEmailBuilder $rawEmailBuilder = new RawEmailBuilder(),
        private SmtpCapabilityParser $capabilityParser = new SmtpCapabilityParser(),
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $message->assertReadyToSend();

        $rawEmail = $this->rawEmailBuilder->build($message, includeSubject: true);
        $messageId = $this->extractMessageId($rawEmail->headers);
        $connection = null;
        $capabilities = new SmtpCapabilities();
        $start = microtime(true);
        $serverGreeting = null;
        $authMechanism = null;
        $sessionStarted = false;
        $report = null;

        try {
            $connection = $this->openConnection();
            [, $serverGreeting] = $this->expect($connection, [220], 'server greeting');
            $sessionStarted = true;

            $capabilities = $this->initializeSession($connection);
            $authMechanism = $this->authenticate($connection, $capabilities);
            $report = $this->sendEmailData(
                $connection,
                $message,
                $rawEmail->raw,
                $rawEmail->sizeBytes,
                $capabilities,
                $messageId,
            );
            $this->write($connection, "QUIT\r\n");

            $metadata = $this->buildRuntimeMetadata(
                $messageId,
                $capabilities,
                $start,
                $serverGreeting,
                $authMechanism,
                $report->metadata,
            );

            if (!$report->successful) {
                return CommunicationResult::failure(
                    $report->error ?? 'SMTP delivery failed.',
                    response: $report,
                    metadata: $metadata,
                );
            }

            return CommunicationResult::success(response: $report, metadata: $metadata);
        } catch (RuntimeException $exception) {
            $metadata = $this->buildRuntimeMetadata(
                $messageId,
                $capabilities,
                $start,
                $serverGreeting,
                $authMechanism,
                ['error_stage' => 'smtp-send'],
            );

            return CommunicationResult::failure(
                $exception->getMessage(),
                response: new EmailDeliveryReport(
                    false,
                    [],
                    [],
                    $messageId,
                    $exception->getMessage(),
                    $metadata,
                ),
                metadata: $metadata,
            );
        } finally {
            if (is_resource($connection)) {
                if ($report === null) {
                    $this->gracefulClose($connection, $sessionStarted);
                }

                fclose($connection);
            }
        }
    }

    private function assertSizeWithinLimit(SmtpCapabilities $capabilities, int $messageSizeBytes): ?int
    {
        $sizeLimit = $capabilities->sizeLimit();
        if ($sizeLimit !== null && $messageSizeBytes > $sizeLimit) {
            throw new RuntimeException(
                sprintf('Email size %d bytes exceeds SMTP SIZE limit %d bytes.', $messageSizeBytes, $sizeLimit),
            );
        }

        return $sizeLimit;
    }

    /**
     * @param resource $connection
     */
    private function authenticate($connection, SmtpCapabilities $capabilities): ?string
    {
        if ($this->config->credentials === null) {
            return null;
        }

        $mechanism = $this->resolveAuthMechanism($capabilities);

        if ($mechanism === SmtpAuthMechanism::Plain) {
            $payload = sprintf(
                "\0%s\0%s",
                $this->config->credentials->username,
                $this->config->credentials->password,
            );

            $this->write($connection, 'AUTH PLAIN ' . base64_encode($payload) . "\r\n");
            $this->expect($connection, [235], 'AUTH PLAIN success');

            return SmtpAuthMechanism::Plain->value;
        }

        $this->write($connection, "AUTH LOGIN\r\n");
        $this->expect($connection, [334], 'AUTH LOGIN challenge');

        $this->write($connection, base64_encode($this->config->credentials->username) . "\r\n");
        $this->expect($connection, [334], 'AUTH username challenge');

        $this->write($connection, base64_encode($this->config->credentials->password) . "\r\n");
        $this->expect($connection, [235], 'AUTH success');

        return SmtpAuthMechanism::Login->value;
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function buildRuntimeMetadata(
        ?string $messageId,
        SmtpCapabilities $capabilities,
        float $startedAt,
        ?string $serverGreeting,
        ?string $authMechanism,
        array $metadata = [],
    ): array {
        $base = [
            'transport' => 'smtp',
            'smtp_host' => $this->config->host,
            'smtp_port' => $this->config->port,
            'security' => $this->config->security->value,
            'ehlo_capabilities' => array_keys($capabilities->values),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'message_id' => $messageId,
            'auth_mechanism' => $authMechanism,
            'server_greeting' => $serverGreeting !== null ? trim($serverGreeting) : null,
        ];

        return array_merge($base, $metadata);
    }

    private function dotStuff(string $payload): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $payload);
        $normalized = preg_replace('/^\./m', '..', $normalized) ?? $normalized;

        return str_replace("\n", "\r\n", $normalized);
    }

    private function dsnNotifyDirective(EmailHeaders $headers): ?string
    {
        $notifyFlags = [];

        if ($headers->dsnNotifySuccess) {
            $notifyFlags[] = 'SUCCESS';
        }

        if ($headers->dsnNotifyFailure) {
            $notifyFlags[] = 'FAILURE';
        }

        if ($headers->dsnNotifyDelay) {
            $notifyFlags[] = 'DELAY';
        }

        if ($notifyFlags === []) {
            return null;
        }

        return implode(',', $notifyFlags);
    }

    /**
     * @param resource $connection
     * @param list<int> $expectedCodes
     *
     * @return array{0:int,1:string,2:list<string>}
     */
    private function expect($connection, array $expectedCodes, string $stage): array
    {
        [$code, $response, $lines] = $this->readResponse($connection);

        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException(
                sprintf(
                    'SMTP error at %s. Expected %s, got %d: %s',
                    $stage,
                    implode(', ', $expectedCodes),
                    $code,
                    trim($response),
                ),
            );
        }

        return [$code, $response, $lines];
    }

    private function extractMessageId(string $headers): ?string
    {
        if (preg_match('/^Message-ID:\\s*(.+)$/mi', $headers, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * @param resource $connection
     */
    private function gracefulClose($connection, bool $sessionStarted): void
    {
        if (!$sessionStarted) {
            return;
        }

        $this->tryWriteAndDrain($connection, "RSET\r\n");
        $this->tryWriteAndDrain($connection, "QUIT\r\n");
    }

    /**
     * @param resource $connection
     */
    private function initializeSession($connection): SmtpCapabilities
    {
        $this->write($connection, sprintf("EHLO %s\r\n", $this->config->localDomain));
        [, , $ehloLines] = $this->expect($connection, [250], 'EHLO');
        $capabilities = $this->capabilityParser->parse($ehloLines);

        if (!$this->shouldAttemptStartTls()) {
            return $capabilities;
        }

        $hasStartTls = $capabilities->has('STARTTLS');
        if (!$hasStartTls && $this->requiresStartTls()) {
            throw new RuntimeException('SMTP server does not advertise STARTTLS, but STARTTLS is required.');
        }

        if (!$hasStartTls) {
            return $capabilities;
        }

        $this->write($connection, "STARTTLS\r\n");
        $this->expect($connection, [220], 'STARTTLS');

        if (!stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS negotiation failed.');
        }

        $this->write($connection, sprintf("EHLO %s\r\n", $this->config->localDomain));
        [, , $secureEhloLines] = $this->expect($connection, [250], 'EHLO after STARTTLS');

        return $this->capabilityParser->parse($secureEhloLines);
    }

    /**
     * @return resource
     */
    private function openConnection()
    {
        $host = $this->config->security === SmtpSecurity::Ssl
            ? sprintf('ssl://%s', $this->config->host)
            : $this->config->host;

        $errno = 0;
        $errstr = '';

        $handler = static function (int $severity, string $message): bool {
            throw new RuntimeException($message, $severity);
        };

        set_error_handler($handler);

        try {
            $connection = fsockopen($host, $this->config->port, $errno, $errstr, $this->config->timeoutSeconds);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($connection)) {
            throw new RuntimeException(sprintf('Failed to connect to SMTP server: %s (%d)', $errstr, $errno));
        }

        stream_set_timeout($connection, $this->config->timeoutSeconds);

        return $connection;
    }

    /**
     * @param resource $connection
     *
     * @return array{0:int,1:string,2:list<string>}
     */
    private function readResponse($connection): array
    {
        $response = '';
        $lines = [];
        $code = 0;

        while (true) {
            $line = fgets($connection, 1024);
            if ($line === false) {
                $metadata = stream_get_meta_data($connection);
                if ($metadata['timed_out'] === true) {
                    throw new RuntimeException('SMTP server response timed out.');
                }

                throw new RuntimeException('Failed to read SMTP server response.');
            }

            $response .= $line;
            $lines[] = rtrim($line, "\r\n");

            if (preg_match('/^(\d{3})([\s-])/', $line, $matches) !== 1) {
                continue;
            }

            $code = (int) $matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        }

        return [$code, $response, $lines];
    }

    private function requiresStartTls(): bool
    {
        return $this->config->security === SmtpSecurity::StartTlsRequired;
    }

    private function resolveAuthMechanism(SmtpCapabilities $capabilities): SmtpAuthMechanism
    {
        if ($this->config->authMechanism !== SmtpAuthMechanism::Auto) {
            if ($capabilities->authMechanisms !== [] && !in_array(strtoupper($this->config->authMechanism->value), $capabilities->authMechanisms, true)) {
                throw new RuntimeException(sprintf(
                    'SMTP server does not advertise AUTH %s.',
                    strtoupper($this->config->authMechanism->value),
                ));
            }

            return $this->config->authMechanism;
        }

        if (in_array('PLAIN', $capabilities->authMechanisms, true)) {
            return SmtpAuthMechanism::Plain;
        }

        if (in_array('LOGIN', $capabilities->authMechanisms, true) || $capabilities->authMechanisms === []) {
            return SmtpAuthMechanism::Login;
        }

        throw new RuntimeException('No supported SMTP AUTH mechanism found (expected PLAIN or LOGIN).');
    }

    /**
     * @param resource $connection
     */
    private function sendEmailData(
        $connection,
        EmailMessage $message,
        string $rawMessage,
        int $messageSizeBytes,
        SmtpCapabilities $capabilities,
        ?string $messageId,
    ): EmailDeliveryReport {
        $envelopeSender = $message->envelope()->envelopeSender();
        if ($envelopeSender === null) {
            throw new RuntimeException('Envelope sender is required for SMTP transport.');
        }

        $sizeLimit = $this->assertSizeWithinLimit($capabilities, $messageSizeBytes);
        $headers = $message->headersData();
        $this->sendMailFrom($connection, $envelopeSender->email, $headers, $capabilities, $messageSizeBytes, $sizeLimit);
        ['accepted' => $acceptedRecipients, 'rejected' => $rejectedRecipients] = $this->sendRecipients(
            $connection,
            $message,
            $headers,
            $capabilities,
        );

        if ($acceptedRecipients === []) {
            return new EmailDeliveryReport(
                false,
                [],
                $rejectedRecipients,
                $messageId,
                'SMTP rejected all recipients.',
                [
                    'transport' => 'smtp',
                    'smtp_host' => $this->config->host,
                    'smtp_port' => $this->config->port,
                    'security' => $this->config->security->value,
                    'ehlo_capabilities' => array_keys($capabilities->values),
                    'message_size_bytes' => $messageSizeBytes,
                ],
            );
        }

        $this->write($connection, "DATA\r\n");
        $this->expect($connection, [354], 'DATA');

        $payload = $this->dotStuff($rawMessage);
        $this->write($connection, $payload . "\r\n.\r\n");
        [, $messageResponse] = $this->expect($connection, [250], 'message body');

        return new EmailDeliveryReport(
            $rejectedRecipients === [],
            $acceptedRecipients,
            $rejectedRecipients,
            $messageId,
            $rejectedRecipients === [] ? null : 'One or more recipients were rejected.',
            [
                'transport' => 'smtp',
                'smtp_host' => $this->config->host,
                'smtp_port' => $this->config->port,
                'security' => $this->config->security->value,
                'ehlo_capabilities' => array_keys($capabilities->values),
                'message_size_bytes' => $messageSizeBytes,
                'accepted_count' => count($acceptedRecipients),
                'rejected_count' => count($rejectedRecipients),
                'partial_success' => $rejectedRecipients !== [],
                'final_response' => trim($messageResponse),
            ],
        );
    }

    /**
     * @param resource $connection
     */
    private function sendMailFrom(
        $connection,
        string $senderEmail,
        EmailHeaders $headers,
        SmtpCapabilities $capabilities,
        int $messageSizeBytes,
        ?int $sizeLimit,
    ): void {
        $mailFromArgs = [];

        if ($capabilities->has('DSN')) {
            $mailFromArgs[] = 'RET=' . ($headers->dsnReturnFull ? 'FULL' : 'HDRS');
            $mailFromArgs[] = 'ENVID=' . bin2hex(random_bytes(8));
        }

        if ($sizeLimit !== null) {
            $mailFromArgs[] = 'SIZE=' . $messageSizeBytes;
        }

        $mailFrom = sprintf('MAIL FROM:<%s>', $senderEmail);
        if ($mailFromArgs !== []) {
            $mailFrom .= ' ' . implode(' ', $mailFromArgs);
        }

        $this->write($connection, $mailFrom . "\r\n");
        $this->expect($connection, [250], 'MAIL FROM');
    }

    /**
     * @param resource $connection
     *
     * @return array{accepted:list<string>,rejected:array<string,string>}
     */
    private function sendRecipients(
        $connection,
        EmailMessage $message,
        EmailHeaders $headers,
        SmtpCapabilities $capabilities,
    ): array {
        $acceptedRecipients = [];
        $rejectedRecipients = [];

        foreach ($message->envelope()->recipients() as $recipient) {
            $rcptCommand = sprintf('RCPT TO:<%s>', $recipient->email);

            if ($capabilities->has('DSN')) {
                $notify = $this->dsnNotifyDirective($headers);
                if ($notify !== null) {
                    $rcptCommand .= ' NOTIFY=' . $notify;
                }
            }

            $this->write($connection, $rcptCommand . "\r\n");
            [$code, $response] = $this->readResponse($connection);

            if (in_array($code, [250, 251], true)) {
                $acceptedRecipients[] = $recipient->email;

                continue;
            }

            $rejectedRecipients[$recipient->email] = trim($response);
        }

        return ['accepted' => $acceptedRecipients, 'rejected' => $rejectedRecipients];
    }

    private function shouldAttemptStartTls(): bool
    {
        return in_array(
            $this->config->security,
            [SmtpSecurity::StartTlsRequired, SmtpSecurity::StartTlsOptional],
            true,
        );
    }

    /**
     * @param resource $connection
     */
    private function tryWriteAndDrain($connection, string $command): void
    {
        try {
            $this->write($connection, $command);
            $this->readResponse($connection);
        } catch (\Throwable) {
            // Best-effort close path; do not override root SMTP error.
        }
    }

    /**
     * @param resource $connection
     */
    private function write($connection, string $data): void
    {
        $dataLength = strlen($data);
        $bytesWritten = 0;

        while ($bytesWritten < $dataLength) {
            $chunk = substr($data, $bytesWritten);
            $written = fwrite($connection, $chunk);

            if ($written === false || $written === 0) {
                throw new RuntimeException('Failed to write to SMTP server socket.');
            }

            $bytesWritten += $written;
        }
    }
}
