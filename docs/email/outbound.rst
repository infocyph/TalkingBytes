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
- ``captureTranscript`` (debug metadata)
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
