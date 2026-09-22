Outbound Delivery
=================

Transports
----------

Outbound entrypoint is ``Emailer`` (usually via ``Email::sender()``).

Supported transports:

- SMTP: ``usingSmtp(SmtpConfig $config)``
- sendmail: ``usingSendmail(SendmailConfig $config = new SendmailConfig())``
- PHP ``mail()``: ``usingMailFunction()``
- spool writer: ``usingSpool(SpoolConfig $config)``
- log transport: ``usingLog(LogEmailConfig $config)``
- null transport: ``usingNull()``
- fake transport: ``fake()``

Message construction
--------------------

``EmailMessage`` is immutable. Chain operations return a new instance.

Core fields:

- envelope: ``from()``, ``to()``, ``cc()``, ``bcc()``
- subject/body: ``subject()``, ``text()``, ``html()``
- headers: ``header()``, ``headers()``, ``withHeaders()``, ``withoutHeader()``
- metadata: ``tag()``, ``withMetadata()``

Attachment methods
------------------

- file attachment: ``attach($path, ?$filename = null, $maxSizeBytes = 26214400)``
- data attachment: ``attachData($content, $name, $mimeType = 'application/octet-stream')``
- stream attachment: ``attachStream($stream, $name, $mimeType = 'application/octet-stream')``
- inline file: ``attachInline($path, $contentId, ?$filename = null)``
- inline data: ``attachInlineData($content, $name, $contentId, $mimeType = 'application/octet-stream')``
- inline stream: ``attachInlineStream($stream, $name, $contentId, $mimeType = 'application/octet-stream')``
- helper: ``embed()`` returns ``[EmailMessage, contentId]``

DKIM and decorators
-------------------

``Emailer`` supports decorator composition:

- ``withDkim(DkimConfig $config)``
- ``withRetry(RetryPolicy $policy)``
- ``withFallback(array $fallbackTransports)``
- ``withRateLimit(RateLimiter $limiter)``
- ``withLogging(callable $logger)``
- ``withPsrLogger(object $logger, string $level = 'info')``

DSN and routing helpers
-----------------------

``EmailMessage`` includes DSN and envelope helpers:

- ``deliveryNotification(success, failure, delay, returnFull, envelopeId)``
- ``dsnEnvelopeId(?string $envelopeId)``
- ``returnPath($mailbox)`` and alias ``bounceTo($mailbox)``
- ``withoutDeliveryNotification()``

Template helper
---------------

``template()`` provides lightweight variable substitution without a full engine.

.. code-block:: php

   $message = EmailMessage::new()
       ->from('sender@example.com')
       ->to('user@example.com')
       ->subject('Welcome')
       ->template('<h1>Hello {{name}}</h1>', ['name' => 'Ari'], asHtml: true);

SMTP configuration
------------------

Important ``SmtpConfig`` options:

- ``security``: ``None``, ``StartTlsOptional``, ``StartTlsRequired``, ``Ssl``
- ``authMechanism``: ``Auto``, ``Plain``, ``Login``, ``None``
- ``utf8Policy``: ``Reject``, ``Auto``, ``Require``
- ``allowEightBitMime``
- ``captureTranscript`` (explicit diagnostic metadata; disabled by default)
- ``maxMessageBytes``

Per-recipient status
--------------------

``EmailDeliveryReport`` provides recipient-level outcome with ``EmailRecipientResult``:

- recipient email
- accepted boolean
- SMTP code
- SMTP response

Behavior notes
--------------

- BCC recipients are part of envelope RCPT flow and are not written into message headers.
- Outbound builder normalizes line endings to CRLF.
- Streaming paths are used for large payload handling in SMTP/sendmail/spool transports.

Sendmail process lifecycle
--------------------------

Sendmail execution uses an argument-array ``proc_open()`` path; no shell command
string is constructed. The process lifetime is bounded by monotonic timeout and
may receive a ``CancellationSignal`` through ``usingSendmail()``.

TalkingBytes first requests graceful termination, waits a bounded grace period,
then forces termination if required. On Unix, when the optional POSIX functions
can successfully place the child into its own process group, termination targets
that group so descendants are cleaned up as well. If process-group isolation is
unavailable or cannot be established, TalkingBytes safely falls back to direct
child termination.

``ext-pcntl`` is not required and normal email transports do not install signal
handlers. A host runtime should translate its own stop/signal policy into a
``CancellationSignal``.

Persistent mailbox ownership
----------------------------

IMAP and POP3 mailbox objects own their connection/session state. Reuse one
instance only within the intended execution/worker scope. Long-running runtimes
should call ``connect()`` explicitly when eager connection is useful and
``logout()`` during deterministic scope cleanup; destructors remain best-effort
shutdown protection.

For watch loops, ``watchUntilCancelled()`` adapts the shared
``CancellationSignal`` while the existing callable stop hook remains available.

SMTP transcript diagnostics
---------------------------

``captureTranscript`` is an explicit opt-in debugging surface, not default
observability. Client authentication payloads are replaced with ``[REDACTED]``
and message DATA bodies are represented only by byte count.

The transcript can still contain envelope addresses, non-authentication SMTP
commands, capability text, and server response text. Treat it as sensitive
diagnostic data, retain it only when necessary, and do not enable it as routine
production logging.
