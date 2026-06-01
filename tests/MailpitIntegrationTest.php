<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\SmtpSecurity;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;

beforeEach(function (): void {
    if (getenv('RUN_MAILPIT_INTEGRATION') !== '1') {
        test()->markTestSkipped('Mailpit integration test is disabled.');
    }

    $this->mailpitApiBase = rtrim((string) (getenv('MAILPIT_API_BASE') ?: 'http://127.0.0.1:8025'), '/');
    $this->emailer = Email::sender()->usingSmtp(new SmtpConfig(
        host: (string) (getenv('SMTP_HOST') ?: '127.0.0.1'),
        port: (int) (getenv('SMTP_PORT') ?: 1025),
        security: SmtpSecurity::None,
        timeoutSeconds: 10,
        localDomain: 'localhost',
    ));

    mailpitRequest($this->mailpitApiBase, 'DELETE', '/api/v1/messages');
});

afterEach(function (): void {
    if (getenv('RUN_MAILPIT_INTEGRATION') !== '1') {
        return;
    }

    mailpitRequest($this->mailpitApiBase, 'DELETE', '/api/v1/messages');
});

it('verifies outbound and inbound-style SMTP flows against Mailpit API', function (): void {
    $outbound = EmailMessage::new()
        ->from('system@talkingbytes.local')
        ->to('user@example.com')
        ->subject('Welcome to TalkingBytes')
        ->text('Thank you for joining.');

    $outboundResult = $this->emailer->send($outbound);
    expect($outboundResult->successful)->toBeTrue();

    $firstBatch = waitForMailpitMessages($this->mailpitApiBase, 1);
    $welcomeMessage = messageBySubject($firstBatch, 'Welcome to TalkingBytes');

    expect($welcomeMessage)->not->toBeNull();
    expect(messageHasRecipient($welcomeMessage, 'user@example.com'))->toBeTrue();

    $reply = EmailMessage::new()
        ->from('user@example.com')
        ->to('support@talkingbytes.local')
        ->subject('Re: Welcome to TalkingBytes')
        ->text('Signup worked great.');

    $replyResult = $this->emailer->send($reply);
    expect($replyResult->successful)->toBeTrue();

    $secondBatch = waitForMailpitMessages($this->mailpitApiBase, 2);
    $replyMessage = messageBySubject($secondBatch, 'Re: Welcome to TalkingBytes');

    expect($replyMessage)->not->toBeNull();
    expect(messageFromAddress($replyMessage))->toBe('user@example.com');
    expect(messageHasRecipient($replyMessage, 'support@talkingbytes.local'))->toBeTrue();
});

it('parses raw MIME message from Mailpit for inbound-style verification', function (): void {
    $message = EmailMessage::new()
        ->from('billing@talkingbytes.local')
        ->to('customer@example.com')
        ->cc('audit@example.com')
        ->bcc('internal@example.com')
        ->subject('Invoice with attachment')
        ->text("Plain text body\r\nLine two")
        ->html('<p>Invoice <strong>details</strong></p><img src="cid:invoice-logo">')
        ->attachData('invoice-content', 'invoice.txt', 'text/plain')
        ->attachInlineData('inline-image-content', 'logo.png', 'invoice-logo', 'image/png');

    $result = $this->emailer->send($message);
    expect($result->successful)->toBeTrue();

    waitForMailpitMessages($this->mailpitApiBase, 1);
    $raw = mailpitRawMessage($this->mailpitApiBase, 'latest');

    expect($raw)->toContain('Subject: Invoice with attachment');
    expect($raw)->toContain('multipart/');
    expect($raw)->not->toContain('Bcc:');

    $parsed = (new RawEmailParser())->parse($raw);

    expect($parsed->subjectOrEmpty())->toBe('Invoice with attachment');
    expect($parsed->fromEmail())->toBe('billing@talkingbytes.local');
    expect($parsed->attachmentCount())->toBeGreaterThanOrEqual(2);
    expect($parsed->htmlBody)->toContain('Invoice');
});

/**
 * @return array<string, mixed>
 */
function mailpitRequest(string $baseUrl, string $method, string $path): array
{
    $handle = curl_init($baseUrl . $path);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize cURL for Mailpit API.');
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
    ]);

    $rawBody = curl_exec($handle);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    if ($rawBody === false) {
        throw new RuntimeException(sprintf('Mailpit API request failed: %s', $curlError));
    }

    if ($statusCode >= 400) {
        throw new RuntimeException(sprintf('Mailpit API returned HTTP %d for %s %s.', $statusCode, $method, $path));
    }

    if ($rawBody === '') {
        return [];
    }

    $decoded = json_decode($rawBody, true);
    if (! is_array($decoded)) {
        throw new RuntimeException('Mailpit API returned invalid JSON.');
    }

    return $decoded;
}

function mailpitRawMessage(string $baseUrl, string $messageId = 'latest'): string
{
    $handle = curl_init($baseUrl . '/api/v1/message/' . rawurlencode($messageId) . '/raw');
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize cURL for Mailpit raw message API.');
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
    ]);

    $rawBody = curl_exec($handle);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);

    if ($rawBody === false) {
        throw new RuntimeException(sprintf('Mailpit raw message API request failed: %s', $curlError));
    }

    if ($statusCode >= 400) {
        throw new RuntimeException(sprintf('Mailpit raw message API returned HTTP %d.', $statusCode));
    }

    if (! is_string($rawBody) || trim($rawBody) === '') {
        throw new RuntimeException('Mailpit raw message API returned an empty body.');
    }

    return $rawBody;
}

/**
 * @return list<array<string, mixed>>
 */
function waitForMailpitMessages(string $baseUrl, int $atLeastTotal, float $timeoutSeconds = 10.0): array
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $payload = mailpitRequest($baseUrl, 'GET', '/api/v1/messages');
        $messages = $payload['messages'] ?? [];
        $total = (int) ($payload['total'] ?? (is_array($messages) ? count($messages) : 0));

        if ($total >= $atLeastTotal && is_array($messages)) {
            /** @var list<array<string, mixed>> $messages */
            return $messages;
        }

        usleep(100000);
    }

    throw new RuntimeException(sprintf('Mailpit did not reach %d message(s) before timeout.', $atLeastTotal));
}

/**
 * @param list<array<string, mixed>> $messages
 *
 * @return null|array<string, mixed>
 */
function messageBySubject(array $messages, string $subject): ?array
{
    foreach ($messages as $message) {
        $candidate = $message['Subject'] ?? $message['subject'] ?? null;
        if (is_string($candidate) && $candidate === $subject) {
            return $message;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $message
 */
function messageFromAddress(array $message): ?string
{
    $from = $message['From'] ?? $message['from'] ?? null;
    if (is_array($from)) {
        $address = $from['Address'] ?? $from['address'] ?? null;

        return is_string($address) ? strtolower($address) : null;
    }

    return is_string($from) ? strtolower($from) : null;
}

/**
 * @param array<string, mixed> $message
 */
function messageHasRecipient(array $message, string $email): bool
{
    $needle = strtolower($email);
    $to = $message['To'] ?? $message['to'] ?? null;

    if (is_string($to)) {
        return strtolower($to) === $needle;
    }

    if (is_array($to)) {
        $singleAddress = $to['Address'] ?? $to['address'] ?? null;
        if (is_string($singleAddress) && strtolower($singleAddress) === $needle) {
            return true;
        }

        foreach ($to as $recipient) {
            if (! is_array($recipient)) {
                continue;
            }

            $address = $recipient['Address'] ?? $recipient['address'] ?? null;
            if (is_string($address) && strtolower($address) === $needle) {
                return true;
            }
        }
    }

    return false;
}
