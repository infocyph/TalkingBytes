# TalkingBytes

Transport-agnostic communication toolkit for PHP (`>=8.4`).

## Highlights

- Outbound email via `SMTP`, `mail()`, `sendmail`, `spool`, `log`, `null`, and fake transports.
- Inbound email parsing from raw `.eml`, spool directories, IMAP mailboxes, and POP3 inboxes.
- DKIM signing (outbound) and DKIM verification (inbound).
- Retry/fallback/rate-limit/logging decorators.
- Core communication middleware pipeline for cross-transport behavior.

## Quick Start

### SMTP send

```php
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\EmailMessage;

$mailer = Emailer::usingSmtp(new SmtpConfig('smtp.example.com'));

$message = EmailMessage::new()
    ->from('noreply@example.com', 'Example')
    ->to('user@example.com')
    ->subject('Welcome')
    ->html('<b>Hello</b>');

$result = $mailer->send($message);
```

### DKIM + DSN + bounce mailbox

```php
use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;

$dkim = DkimConfig::fromPrivateKeyPath('example.com', 'mail', __DIR__ . '/dkim-private.pem');

$message = EmailMessage::new()
    ->from('noreply@example.com')
    ->to('user@example.com')
    ->subject('Invoice')
    ->text('Attached invoice')
    ->bounceTo('bounces@example.com')
    ->deliveryNotification(failure: true, delay: true, envelopeId: 'invoice-1001');
```

### DKIM verify

```php
use Infocyph\TalkingBytes\Email\Dkim\DkimVerifier;
use Infocyph\TalkingBytes\Email\Dkim\DnsDkimPublicKeyResolver;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;

$parsed = (new RawEmailParser())->parse($rawEml);
$verification = (new DkimVerifier(new DnsDkimPublicKeyResolver()))->verify($parsed);
```

### Sendmail / spool / null transports

```php
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Emailer;

$sendmail = Emailer::usingSendmail(new SendmailConfig('/usr/sbin/sendmail'));
$spool = Emailer::usingSpool(__DIR__ . '/storage/outbound-emails');
$null = Emailer::usingNull();
```

### mail() transport

```php
use Infocyph\TalkingBytes\Email\Emailer;

$mail = Emailer::usingMailFunction();
```

### Inline image and stream attachment

```php
$message = EmailMessage::new()
    ->from('sender@example.com')
    ->to('user@example.com')
    ->subject('Inline logo')
    ->html('<img src="cid:logo">')
    ->attachInline('/path/logo.png', 'logo')
    ->attachData('csv,data', 'report.csv', 'text/csv');
```

### Templated message

```php
$message = EmailMessage::new()
    ->from('sender@example.com')
    ->to('user@example.com')
    ->subject('Template')
    ->template('<h1>Hello {{name}}</h1>', ['name' => 'Alice']);
```

### Spool receiver

```php
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;

$receiver = new SpoolEmailReceiver(new SpoolConfig(__DIR__ . '/storage/inbound-emails'));
$email = $receiver->receive();
```

### Spool sender

```php
use Infocyph\TalkingBytes\Email\Emailer;

$spoolSender = Emailer::usingSpool(__DIR__ . '/storage/outbound-emails');
$spoolSender->send($message);
```

### IMAP mailbox

```php
use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\MailboxSearch;

$mailbox = Mailbox::usingImap(new ImapConfig(
    host: 'imap.example.com',
    username: 'user',
    password: 'secret',
));

$messages = $mailbox->folder('INBOX')->query(
    MailboxSearch::new()->unseen()->newestFirst()->limit(20)
);

$parsed = $mailbox->folder('INBOX')->fetchParsed($messages[0]->uid);
```

### POP3 mailbox

```php
use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;

$mailbox = Pop3Mailbox::usingConfig(new Pop3Config(
    host: 'pop3.example.com',
    username: 'user',
    password: 'secret',
));

$parsed = $mailbox->fetchParsed(1);
```

### Bounce parsing

```php
use Infocyph\TalkingBytes\Email\Parser\BounceParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;

$parsed = (new RawEmailParser())->parse($rawEml);
$bounce = (new BounceParser())->parse($parsed);
$reports = (new BounceParser())->parseMany($parsed);
```

### Authentication-Results parsing

```php
use Infocyph\TalkingBytes\Email\Parser\AuthenticationResultsParser;

$results = (new AuthenticationResultsParser())->parse(
    'mx.example.com; dkim=pass header.d=example.com; spf=pass smtp.mailfrom=example.com; dmarc=pass header.from=example.com'
);

$authenticated = $results->isAuthenticated();
```

### Parser limits

```php
use Infocyph\TalkingBytes\Email\Config\EmailLimits;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;

$parser = new RawEmailParser(new EmailLimits(
    maxMessageBytes: 5 * 1024 * 1024,
    maxMimeDepth: 16,
    maxMimeParts: 300,
));
```

## Event Hooks

You can subscribe to email and mailbox events:

```php
use Infocyph\TalkingBytes\Email\Email;

Email::events(static function (string $event, array $payload): void {
    // email.send.start, email.send.finish,
    // email.receive.start, email.receive.finish,
    // email.parse.failed, mailbox.command.start, mailbox.command.finish,
    // bounce.detected
});
```

Mailbox command payloads are redacted before dispatch:

```php
// LOGIN "user" [REDACTED]
// AUTHENTICATE [REDACTED]
// PASS [REDACTED]
// APOP user [REDACTED]
// AUTH [REDACTED]
```

## Limits and Safety

- Parser and mailbox operations enforce `EmailLimits`.
- Header injection protections are enabled for outbound headers.
- SMTP supports STARTTLS required/optional policies and capability-aware auth.
- Mailbox command events redact sensitive auth payloads (`LOGIN`, `AUTHENTICATE`, `PASS`, `APOP`, `AUTH`).
- Attachment filenames are normalized for filesystem-safe storage.
- DKIM DNS verification treats empty `p=` keys as revoked and ignores unrelated TXT records.
- `DkimAlgorithm::Ed25519Sha256` is reserved for forward compatibility; signer currently supports `rsa-sha256`.

## Performance Notes

- Streaming send paths are used for SMTP `DATA`, sendmail stdin, and spool temp-file writes.
- `RawEmailBuilder::buildToStream()` can enforce max-bytes output limits for large messages.
- Lazy IMAP attachment resolution fetches `BODY.PEEK[part]` only when attachment contents are requested.
- Date sorting and attachment-style searches can be expensive on large folders; use `limit()`.
- Use `MailboxSearch::maxSummaryFetches()` and `MailboxSearch::maxClientSideFilterFetches()` to cap query cost.

## Notes / Limitations

- Socket integration tests may be skipped in restricted environments where local TCP bind is unavailable.
- POP3 supports `INBOX` only (protocol limitation).
- POP3 message numbers are session/order based. Prefer `UIDL` when available before destructive actions.
- POP3 deletions are committed on `QUIT`; `RSET` can clear pending deletions before logout.
- IMAP `IDLE` support falls back to `NOOP` polling when the server does not advertise `IDLE`.
- Expensive mailbox searches (for example attachment-text criteria or date sorting with summary fetches) should use `limit()`.
- For very large folders, use `MailboxSearch::maxSummaryFetches()` and `MailboxSearch::maxClientSideFilterFetches()` guards.
- POP3 message numbers are not stable IDs across sessions/deletes; prefer `UIDL` for external tracking.
- Batch mailbox helpers (`markSeenMany`, `deleteMany`, `moveMany`, etc.) stop on first failure by design.

## Extension Policy

- Required:
  - `ext-curl` (HTTP/cURL transport)
  - `ext-fileinfo` (attachment MIME detection)
  - `ext-openssl` (DKIM sign/verify and TLS socket crypto)
- Suggested:
  - `ext-mbstring` (charset conversion + UTF7-IMAP conversion path)
  - `ext-iconv` (charset fallback conversion path)
  - `ext-imap` (optional address parsing and UTF7-IMAP fallback helpers)
- Graceful fallback:
  - Address parsing and UTF7-IMAP conversion continue without `ext-imap` using internal fallbacks.
  - Charset conversion attempts `mbstring` first, then `iconv`; if unavailable conversion falls back to input bytes.

## Naming Map

- `Emailer` = outbound sending API
- `EmailReceiver` = one-by-one inbound source API
- `Mailbox` = IMAP/foldered mailbox API
- `Pop3Mailbox` = POP3 mailbox API
- `RawEmailParser` = raw `.eml` parsing API

## Release Checklist

- No sensitive credentials in logs/events (SMTP transcript redaction and mailbox command redaction verified).
- Header and command-injection guards enabled for SMTP/IMAP/POP3/sendmail entry points.
- Parser size/depth/header/attachment limits configured and tested.
- Streaming paths available for large outbound payloads.
- POP3 protocol limitations and IMAP/SMTP STARTTLS recommendations documented.
