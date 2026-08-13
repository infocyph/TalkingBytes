<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\SmtpAuthMechanism;
use Infocyph\TalkingBytes\Email\Enum\SmtpSecurity;
use Infocyph\TalkingBytes\Email\Result\EmailDeliveryReport;
use Infocyph\TalkingBytes\Email\Result\EmailRecipientResult;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;
use Infocyph\TalkingBytes\Email\System\SmtpCapabilities;
use Infocyph\TalkingBytes\Email\System\SmtpCapabilityParser;
use Infocyph\TalkingBytes\Email\System\SmtpEnvelopePlanner;
use Infocyph\TalkingBytes\Email\System\SmtpMessageStreamPreparer;
use Infocyph\TalkingBytes\Email\System\SmtpTlsContext;
use Infocyph\TalkingBytes\Email\ValueObject\EmailHeaders;
use RuntimeException;

final readonly class SmtpTransport implements EmailTransport
{
    public function __construct(
        private SmtpConfig $config,
        private RawEmailBuilder $rawEmailBuilder = new RawEmailBuilder(),
        private SmtpCapabilityParser $capabilityParser = new SmtpCapabilityParser(),
        private ?SmtpEnvelopePlanner $envelopePlanner = null,
        private SmtpTlsContext $tlsContext = new SmtpTlsContext(),
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $message = $message->prepare();
        $messageId = $message->headersData()->messageId;
        $connection = null;
        $messageStream = null;
        $capabilities = new SmtpCapabilities();
        $start = microtime(true);
        $serverGreeting = null;
        $authMechanism = null;
        $sessionStarted = false;
        $report = null;
        $transcript = [];

        try {
            $prepared = new SmtpMessageStreamPreparer(
                $this->rawEmailBuilder,
                $this->config->maxMessageBytes,
            )->prepare($message);
            $messageStream = $prepared['stream'];
            $connection = $this->openConnection();
            [, $serverGreeting] = $this->expect($connection, [220], 'server greeting', $transcript);
            $sessionStarted = true;

            $capabilities = $this->initializeSession($connection, $transcript);
            $authMechanism = $this->authenticate($connection, $capabilities, $transcript);
            $report = $this->sendEmailData(
                $connection,
                $message,
                $messageStream,
                $prepared['sizeBytes'],
                $prepared['containsNonAscii'],
                $capabilities,
                $messageId,
                $transcript,
            );
            $this->write($connection, "QUIT\r\n", $transcript);

            $metadata = $this->buildRuntimeMetadata(
                $messageId,
                $capabilities,
                $start,
                $serverGreeting,
                $authMechanism,
                $report->metadata,
                $transcript,
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
                $transcript,
            );

            return CommunicationResult::failure(
                $exception->getMessage(),
                response: new EmailDeliveryReport(
                    false,
                    [],
                    [],
                    $messageId,
                    $exception->getMessage(),
                    [],
                    $metadata,
                ),
                metadata: $metadata,
            );
        } finally {
            if (is_resource($connection)) {
                if ($report === null) {
                    $this->gracefulClose($connection, $sessionStarted, $transcript);
                }

                fclose($connection);
            }
            if (is_resource($messageStream)) {
                fclose($messageStream);
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
     * @param list<string> $transcript
     */
    private function authenticate($connection, SmtpCapabilities $capabilities, array &$transcript): ?string
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

            $this->write($connection, 'AUTH PLAIN ' . base64_encode($payload) . "\r\n", $transcript, sensitive: true);
            $this->expect($connection, [235], 'AUTH PLAIN success', $transcript);

            return SmtpAuthMechanism::Plain->value;
        }

        $this->write($connection, "AUTH LOGIN\r\n", $transcript);
        $this->expect($connection, [334], 'AUTH LOGIN challenge', $transcript);

        $this->write($connection, base64_encode($this->config->credentials->username) . "\r\n", $transcript, sensitive: true);
        $this->expect($connection, [334], 'AUTH username challenge', $transcript);

        $this->write($connection, base64_encode($this->config->credentials->password) . "\r\n", $transcript, sensitive: true);
        $this->expect($connection, [235], 'AUTH success', $transcript);

        return SmtpAuthMechanism::Login->value;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<string> $transcript
     * @return array<string, mixed>
     */
    private function buildRuntimeMetadata(
        ?string $messageId,
        SmtpCapabilities $capabilities,
        float $startedAt,
        ?string $serverGreeting,
        ?string $authMechanism,
        array $metadata = [],
        array $transcript = [],
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

        if ($this->config->captureTranscript) {
            $base['smtp_transcript'] = $transcript;
        }

        return array_merge($base, $metadata);
    }

    /**
     * @param resource $connection
     * @param list<int> $expectedCodes
     * @param list<string> $transcript
     * @return array{0:int,1:string,2:list<string>}
     */
    private function expect($connection, array $expectedCodes, string $stage, array &$transcript = []): array
    {
        [$code, $response, $lines] = $this->readResponse($connection, $transcript);

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

    /**
     * @param resource $connection
     * @param list<string> $transcript
     */
    private function gracefulClose($connection, bool $sessionStarted, array &$transcript): void
    {
        if (!$sessionStarted) {
            return;
        }

        $this->tryWriteAndDrain($connection, "RSET\r\n", $transcript);
        $this->tryWriteAndDrain($connection, "QUIT\r\n", $transcript);
    }

    /**
     * @param resource $connection
     * @param list<string> $transcript
     */
    private function initializeSession($connection, array &$transcript): SmtpCapabilities
    {
        $this->write($connection, sprintf("EHLO %s\r\n", $this->config->localDomain), $transcript);
        [, , $ehloLines] = $this->expect($connection, [250], 'EHLO', $transcript);
        $capabilities = $this->capabilityParser->parse($ehloLines);

        if (!$this->shouldAttemptStartTls()) {
            return $capabilities;
        }

        $hasStartTls = $capabilities->has('STARTTLS');
        if (!$hasStartTls && $this->requiresStartTls()) {
            throw new RuntimeException('SMTP server does not advertise STARTTLS, but STARTTLS is required.');
        }

        if (!$hasStartTls) {
            if ($this->config->credentials !== null) {
                throw new RuntimeException('SMTP STARTTLS is unavailable; refusing to send credentials.');
            }

            return $capabilities;
        }

        $this->write($connection, "STARTTLS\r\n", $transcript);
        $this->expect($connection, [220], 'STARTTLS', $transcript);

        if (!stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS negotiation failed.');
        }

        $this->write($connection, sprintf("EHLO %s\r\n", $this->config->localDomain), $transcript);
        [, , $secureEhloLines] = $this->expect($connection, [250], 'EHLO after STARTTLS', $transcript);

        return $this->capabilityParser->parse($secureEhloLines);
    }

    /**
     * @return resource
     */
    private function openConnection()
    {
        $host = sprintf(
            '%s://%s:%d',
            $this->config->security === SmtpSecurity::Ssl ? 'ssl' : 'tcp',
            $this->config->host,
            $this->config->port,
        );

        $errno = 0;
        $errstr = '';

        $handler = static function (int $severity, string $message): bool {
            throw new RuntimeException($message, $severity);
        };

        set_error_handler($handler);

        try {
            $connection = stream_socket_client(
                $host,
                $errno,
                $errstr,
                $this->config->timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                stream_context_create(['ssl' => $this->tlsContext->options($this->config)]),
            );
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
     * @param list<string> $transcript
     * @return array{0:int,1:string,2:list<string>}
     */
    private function readResponse($connection, array &$transcript = []): array
    {
        $response = '';
        $lines = [];
        $code = 0;
        $deadline = microtime(true) + $this->config->timeoutSeconds;

        while (true) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('SMTP command deadline exceeded.');
            }

            $line = fgets($connection, 1024);
            if ($line === false) {
                $metadata = stream_get_meta_data($connection);
                if ($metadata['timed_out']) {
                    throw new RuntimeException('SMTP server response timed out.');
                }

                throw new RuntimeException('Failed to read SMTP server response.');
            }

            if (!str_ends_with($line, "\n") && !feof($connection)) {
                throw new RuntimeException('SMTP response line exceeds 1023 bytes.');
            }

            $response .= $line;
            $this->recordTranscriptResponse($transcript, $line);
            $lines[] = rtrim($line, "\r\n");
            if (count($lines) > 100 || strlen($response) > 65_536) {
                throw new RuntimeException('SMTP response exceeds protocol bounds.');
            }

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

    /**
     * @param list<string> $transcript
     */
    private function recordTranscriptCommand(array &$transcript, string $command, bool $sensitive, bool $dataPayload): void
    {
        if (!$this->config->captureTranscript) {
            return;
        }

        if ($dataPayload) {
            $transcript[] = sprintf('C: [DATA %d bytes]', strlen($command));

            return;
        }

        $line = trim(str_replace(["\r", "\n"], '', $command));
        if ($line === '') {
            return;
        }

        if ($sensitive || str_starts_with($line, 'AUTH ')) {
            $transcript[] = 'C: [REDACTED]';

            return;
        }

        $transcript[] = 'C: ' . $line;
    }

    /**
     * @param list<string> $transcript
     */
    private function recordTranscriptResponse(array &$transcript, string $responseLine): void
    {
        if (!$this->config->captureTranscript) {
            return;
        }

        $trimmed = trim($responseLine);
        if ($trimmed === '') {
            return;
        }

        $transcript[] = 'S: ' . $trimmed;
    }

    private function requiresStartTls(): bool
    {
        return $this->config->security === SmtpSecurity::StartTlsRequired;
    }

    private function resolveAuthMechanism(SmtpCapabilities $capabilities): SmtpAuthMechanism
    {
        if ($this->config->authMechanism !== SmtpAuthMechanism::Auto) {
            if (!in_array(strtoupper($this->config->authMechanism->value), $capabilities->authMechanisms, true)) {
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

        if (in_array('LOGIN', $capabilities->authMechanisms, true)) {
            return SmtpAuthMechanism::Login;
        }

        throw new RuntimeException('No supported SMTP AUTH mechanism found (expected PLAIN or LOGIN).');
    }

    /**
     * @param resource $connection
     * @param resource $messageStream
     * @param list<string> $transcript
     */
    private function sendEmailData(
        $connection,
        EmailMessage $message,
        $messageStream,
        int $messageSizeBytes,
        bool $messageContainsNonAscii,
        SmtpCapabilities $capabilities,
        ?string $messageId,
        array &$transcript,
    ): EmailDeliveryReport {
        $envelopeSender = $message->envelope()->envelopeSender();
        if ($envelopeSender === null) {
            throw new RuntimeException('Envelope sender is required for SMTP transport.');
        }

        $sizeLimit = $this->assertSizeWithinLimit($capabilities, $messageSizeBytes);
        $headers = $message->headersData();
        $planner = $this->envelopePlanner ?? new SmtpEnvelopePlanner($this->config);
        $requiresUtf8 = $planner->assertSmtpUtf8Policy($message, $capabilities);

        if ($capabilities->has('PIPELINING')) {
            ['accepted' => $acceptedRecipients, 'rejected' => $rejectedRecipients, 'results' => $recipientResults] = $this->sendMailFromAndRecipientsPipelined(
                $connection,
                $message,
                $headers,
                $capabilities,
                $messageSizeBytes,
                $sizeLimit,
                $messageContainsNonAscii,
                $requiresUtf8,
                $planner,
                $transcript,
            );
        } else {
            $this->sendMailFrom(
                $connection,
                $envelopeSender->email,
                $headers,
                $capabilities,
                $messageSizeBytes,
                $sizeLimit,
                $messageContainsNonAscii,
                $requiresUtf8,
                $planner,
                $transcript,
            );
            ['accepted' => $acceptedRecipients, 'rejected' => $rejectedRecipients, 'results' => $recipientResults] = $this->sendRecipients(
                $connection,
                $message,
                $headers,
                $capabilities,
                $planner,
                $transcript,
            );
        }

        if ($acceptedRecipients === []) {
            return new EmailDeliveryReport(
                false,
                [],
                $rejectedRecipients,
                $messageId,
                'SMTP rejected all recipients.',
                $recipientResults,
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

        $this->write($connection, "DATA\r\n", $transcript);
        $this->expect($connection, [354], 'DATA', $transcript);

        $this->writeDataPayloadFromStream($connection, $messageStream, $messageSizeBytes, $transcript);
        [, $messageResponse] = $this->expect($connection, [250], 'message body', $transcript);

        return new EmailDeliveryReport(
            $rejectedRecipients === [],
            $acceptedRecipients,
            $rejectedRecipients,
            $messageId,
            $rejectedRecipients === [] ? null : 'One or more recipients were rejected.',
            $recipientResults,
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
     * @param list<string> $transcript
     */
    private function sendMailFrom(
        $connection,
        string $senderEmail,
        EmailHeaders $headers,
        SmtpCapabilities $capabilities,
        int $messageSizeBytes,
        ?int $sizeLimit,
        bool $messageContainsNonAscii,
        bool $requiresSmtpUtf8,
        SmtpEnvelopePlanner $planner,
        array &$transcript,
    ): void {
        $mailFrom = $planner->buildMailFromCommand(
            $senderEmail,
            $headers,
            $capabilities,
            $messageSizeBytes,
            $sizeLimit,
            $messageContainsNonAscii,
            $requiresSmtpUtf8,
        );

        $this->write($connection, $mailFrom . "\r\n", $transcript);
        $this->expect($connection, [250], 'MAIL FROM', $transcript);
    }

    /**
     * @param resource $connection
     * @param list<string> $transcript
     * @return array{accepted:list<string>,rejected:array<string,string>,results:list<EmailRecipientResult>}
     */
    private function sendMailFromAndRecipientsPipelined(
        $connection,
        EmailMessage $message,
        EmailHeaders $headers,
        SmtpCapabilities $capabilities,
        int $messageSizeBytes,
        ?int $sizeLimit,
        bool $messageContainsNonAscii,
        bool $requiresSmtpUtf8,
        SmtpEnvelopePlanner $planner,
        array &$transcript,
    ): array {
        $sender = $message->envelope()->envelopeSender();
        if ($sender === null) {
            throw new RuntimeException('Envelope sender is required for SMTP transport.');
        }

        $mailFromCommand = $planner->buildMailFromCommand(
            $sender->email,
            $headers,
            $capabilities,
            $messageSizeBytes,
            $sizeLimit,
            $messageContainsNonAscii,
            $requiresSmtpUtf8,
        );

        $commands = [];
        foreach ($message->envelope()->recipients() as $recipient) {
            $commands[] = ['email' => $recipient->email, 'command' => $planner->buildRcptCommand($recipient->email, $headers, $capabilities)];
        }

        $this->write($connection, $mailFromCommand . "\r\n", $transcript);
        foreach ($commands as $recipientCommand) {
            $this->write($connection, $recipientCommand['command'] . "\r\n", $transcript);
        }

        [$mailFromCode, $mailFromResponse] = $this->readResponse($connection, $transcript);
        if (!in_array($mailFromCode, [250], true)) {
            throw new RuntimeException(sprintf('SMTP error at MAIL FROM. Expected 250, got %d: %s', $mailFromCode, trim($mailFromResponse)));
        }

        $acceptedRecipients = [];
        $rejectedRecipients = [];
        $recipientResults = [];

        foreach ($commands as $recipientCommand) {
            [$code, $response] = $this->readResponse($connection, $transcript);
            $email = $recipientCommand['email'];

            if (in_array($code, [250, 251], true)) {
                $acceptedRecipients[] = $email;
                $recipientResults[] = new EmailRecipientResult($email, true, $code, trim($response));

                continue;
            }

            $rejectedRecipients[$email] = trim($response);
            $recipientResults[] = new EmailRecipientResult($email, false, $code > 0 ? $code : null, trim($response));
        }

        return ['accepted' => $acceptedRecipients, 'rejected' => $rejectedRecipients, 'results' => $recipientResults];
    }

    /**
     * @param resource $connection
     * @param list<string> $transcript
     * @return array{accepted:list<string>,rejected:array<string,string>,results:list<EmailRecipientResult>}
     */
    private function sendRecipients(
        $connection,
        EmailMessage $message,
        EmailHeaders $headers,
        SmtpCapabilities $capabilities,
        SmtpEnvelopePlanner $planner,
        array &$transcript,
    ): array {
        $acceptedRecipients = [];
        $rejectedRecipients = [];
        $recipientResults = [];

        foreach ($message->envelope()->recipients() as $recipient) {
            $rcptCommand = $planner->buildRcptCommand($recipient->email, $headers, $capabilities);

            $this->write($connection, $rcptCommand . "\r\n", $transcript);
            [$code, $response] = $this->readResponse($connection, $transcript);

            if (in_array($code, [250, 251], true)) {
                $acceptedRecipients[] = $recipient->email;
                $recipientResults[] = new EmailRecipientResult(
                    $recipient->email,
                    true,
                    $code,
                    trim($response),
                );

                continue;
            }

            $rejectedRecipients[$recipient->email] = trim($response);
            $recipientResults[] = new EmailRecipientResult(
                $recipient->email,
                false,
                $code > 0 ? $code : null,
                trim($response),
            );
        }

        return ['accepted' => $acceptedRecipients, 'rejected' => $rejectedRecipients, 'results' => $recipientResults];
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
     * @param list<string> $transcript
     */
    private function tryWriteAndDrain($connection, string $command, array &$transcript): void
    {
        try {
            $this->write($connection, $command, $transcript);
            $this->readResponse($connection, $transcript);
        } catch (\Throwable) {
            // Best-effort close path; do not override root SMTP error.
        }
    }

    /**
     * @param resource $connection
     * @param list<string> $transcript
     */
    private function write($connection, string $data, array &$transcript = [], bool $sensitive = false, bool $dataPayload = false): void
    {
        $this->recordTranscriptCommand($transcript, $data, $sensitive, $dataPayload);

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

    /**
     * @param resource $connection
     * @param resource $messageStream
     * @param list<string> $transcript
     */
    private function writeDataPayloadFromStream($connection, $messageStream, int $messageSizeBytes, array &$transcript): void
    {
        if ($this->config->captureTranscript) {
            $transcript[] = sprintf('C: [DATA %d bytes]', $messageSizeBytes);
        }

        rewind($messageStream);
        while (($line = fgets($messageStream)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line !== '' && $line[0] === '.') {
                $line = '.' . $line;
            }
            $this->write($connection, $line . "\r\n");
        }

        $this->write($connection, ".\r\n");
    }
}
