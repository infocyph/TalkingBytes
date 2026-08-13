<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\SmtpSecurity;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;

if (getenv('RUN_MAILPIT_INTEGRATION') !== '1') {
    return;
}

beforeEach(function (): void {
    $this->mailpitApiBase = rtrim((string) (getenv('MAILPIT_API_BASE') ?: 'http://127.0.0.1:8025'), '/');
    $this->emailer = Email::sender()->usingSmtp(new SmtpConfig(
        host: (string) (getenv('SMTP_HOST') ?: '127.0.0.1'),
        port: (int) (getenv('SMTP_PORT') ?: 1025),
        security: SmtpSecurity::None,
        timeoutSeconds: 10,
        localDomain: 'localhost',
    ));

    waitForMailpitApiReady($this->mailpitApiBase);
    mailpitRequest($this->mailpitApiBase, 'DELETE', '/api/v1/messages', expectJson: false);
});

afterEach(function (): void {
    mailpitRequest($this->mailpitApiBase, 'DELETE', '/api/v1/messages', expectJson: false);
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
    expect($raw)->not->toContain("\r\nBcc:");

    $parsed = (new RawEmailParser())->parse($raw);

    expect($parsed->subjectOrEmpty())->toBe('Invoice with attachment');
    expect($parsed->fromEmail())->toBe('billing@talkingbytes.local');
    expect($parsed->attachmentCount())->toBeGreaterThanOrEqual(2);
    $hasHtmlPart = parsedPartTreeHasContentType($parsed->parts, 'text/html');
    expect($hasHtmlPart || str_contains(strtolower($raw), 'content-type: text/html'))->toBeTrue();
});

it('keeps BCC out of raw headers while preserving To and Cc', function (): void {
    $message = EmailMessage::new()
        ->from('sender@talkingbytes.local')
        ->to('to@example.com')
        ->cc('cc@example.com')
        ->bcc('bcc@example.com')
        ->subject('Header visibility')
        ->text('Header visibility body');

    $result = $this->emailer->send($message);
    expect($result->successful)->toBeTrue();

    waitForMailpitMessages($this->mailpitApiBase, 1);
    $raw = mailpitRawMessage($this->mailpitApiBase, 'latest');
    $parsed = (new RawEmailParser())->parse($raw);

    expect($raw)->not->toContain("\r\nBcc:");
    expect($parsed->to->count())->toBeGreaterThanOrEqual(1);
    expect($parsed->cc->count())->toBeGreaterThanOrEqual(1);
});

it('preserves list and custom headers in real SMTP delivery', function (): void {
    $message = EmailMessage::new()
        ->from('sender@talkingbytes.local')
        ->to('user@example.com')
        ->subject('List header check')
        ->text('List header body')
        ->listHeaders(
            listId: 'talkingbytes.list',
            unsubscribe: '<https://example.com/unsub>',
            subscribe: '<https://example.com/sub>',
        )
        ->oneClickUnsubscribe('https://example.com/unsub')
        ->header('X-Correlation-Id', 'tb-mailpit-1');

    $result = $this->emailer->send($message);
    expect($result->successful)->toBeTrue();

    waitForMailpitMessages($this->mailpitApiBase, 1);
    $raw = mailpitRawMessage($this->mailpitApiBase, 'latest');
    $parsed = (new RawEmailParser())->parse($raw);

    expect((string) $parsed->header('List-ID'))->toContain('talkingbytes.list');
    expect($parsed->header('List-Unsubscribe'))->toContain('https://example.com/unsub');
    expect($parsed->header('List-Unsubscribe-Post'))->toBe('List-Unsubscribe=One-Click');
    expect($parsed->header('X-Correlation-Id'))->toBe('tb-mailpit-1');
});

it('round-trips utf8 subject and display names through real SMTP', function (): void {
    $message = EmailMessage::new()
        ->from('billing@talkingbytes.local', 'রিপোর্ট টিম')
        ->to('user@example.com')
        ->subject('ফেব্রুয়ারি রিপোর্ট')
        ->text('UTF-8 content');

    $result = $this->emailer->send($message);
    expect($result->successful)->toBeTrue();

    waitForMailpitMessages($this->mailpitApiBase, 1);
    $raw = mailpitRawMessage($this->mailpitApiBase, 'latest');
    $parsed = (new RawEmailParser())->parse($raw);

    expect($parsed->subjectOrEmpty())->toBe('ফেব্রুয়ারি রিপোর্ট');
    expect($parsed->from->first()?->name)->toBe('রিপোর্ট টিম');
});

it('extracts inline and regular attachments from real SMTP raw source', function (): void {
    $message = EmailMessage::new()
        ->from('sender@talkingbytes.local')
        ->to('user@example.com')
        ->subject('Attachment extraction')
        ->text('Attachment extraction body')
        ->html('<p>Inline image below</p><img src="cid:logo-cid">')
        ->attachInlineData('inline-image-data', 'logo.png', 'logo-cid', 'image/png')
        ->attachData('pdf-content', 'invoice.pdf', 'application/pdf');

    $result = $this->emailer->send($message);
    expect($result->successful)->toBeTrue();

    waitForMailpitMessages($this->mailpitApiBase, 1);
    $raw = mailpitRawMessage($this->mailpitApiBase, 'latest');
    $parsed = (new RawEmailParser())->parse($raw);

    expect($parsed->attachmentCount())->toBeGreaterThanOrEqual(2);
    expect(array_any($parsed->attachments, static fn($attachment): bool => $attachment->isInline()))->toBeTrue();
    expect(array_any($parsed->attachments, static fn($attachment): bool => $attachment->safeFilename() === 'invoice.pdf'))->toBeTrue();
});

it('keeps multipart alternative plain and html bodies parseable', function (): void {
    $message = EmailMessage::new()
        ->from('sender@talkingbytes.local')
        ->to('user@example.com')
        ->subject('Alternative body')
        ->text('This is plain body.')
        ->html('<p>This is <strong>HTML</strong> body.</p>');

    $result = $this->emailer->send($message);
    expect($result->successful)->toBeTrue();

    waitForMailpitMessages($this->mailpitApiBase, 1);
    $raw = mailpitRawMessage($this->mailpitApiBase, 'latest');
    $parsed = (new RawEmailParser())->parse($raw);

    $hasPlainPart = parsedPartTreeHasContentType($parsed->parts, 'text/plain');
    $hasHtmlPart = parsedPartTreeHasContentType($parsed->parts, 'text/html');

    expect($hasPlainPart || str_contains(strtolower($raw), 'content-type: text/plain'))->toBeTrue();
    expect($hasHtmlPart || str_contains(strtolower($raw), 'content-type: text/html'))->toBeTrue();
});

/**
 * @return array<string, mixed>
 */
function mailpitRequest(string $baseUrl, string $method, string $path, bool $expectJson = true): array
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

    if ($rawBody === '' || $expectJson === false) {
        return [];
    }

    $decoded = json_decode($rawBody, true);
    if (! is_array($decoded)) {
        throw new RuntimeException(sprintf(
            'Mailpit API returned invalid JSON for %s %s. Body preview: %s',
            $method,
            $path,
            substr($rawBody, 0, 200),
        ));
    }

    return $decoded;
}

function waitForMailpitApiReady(string $baseUrl, float $timeoutSeconds = 10.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        try {
            $info = mailpitRequest($baseUrl, 'GET', '/api/v1/info');
            if ($info !== []) {
                return;
            }
        } catch (RuntimeException) {
            // Wait and retry until Mailpit API is ready.
        }

        usleep(100000);
    }

    throw new RuntimeException('Mailpit API did not become ready in time.');
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

/**
 * @param list<Infocyph\TalkingBytes\Email\ValueObject\ParsedEmailPart> $parts
 */
function parsedPartTreeHasContentType(array $parts, string $contentTypePrefix): bool
{
    foreach ($parts as $part) {
        if (str_starts_with(strtolower($part->contentType), strtolower($contentTypePrefix))) {
            return true;
        }

        if ($part->children !== [] && parsedPartTreeHasContentType($part->children, $contentTypePrefix)) {
            return true;
        }
    }

    return false;
}
