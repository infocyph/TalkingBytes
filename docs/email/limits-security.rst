Email Limits and Security
=========================

Central limits
--------------

``EmailLimits`` centralizes parser and payload bounds.

Main fields:

- ``maxMessageBytes``
- ``maxAttachmentBytes``
- ``maxAttachmentCount``
- ``maxMimeDepth``
- ``maxMimeParts``
- ``maxHeaderBytes``
- ``maxHeaderCount``
- ``maxHeaderLineBytes``
- ``maxDecodedBodyBytes``

``EmailLimits`` is enforced by ``RawEmailParser`` and parser-backed mailbox
fetches. ``SpoolConfig::maxMessageBytes`` is a separate pre-parse spool read
bound; the default spool parser then applies its own ``EmailLimits``.

Header and command safety
-------------------------

Guards are applied to prevent injection:

- ``HeaderValueGuard`` for outbound header values
- ``MailboxUidGuard`` for UID commands
- ``ImapPartNumberGuard`` for ``BODY.PEEK[...]`` sections
- ``MailboxFlagGuard`` for flag formats
- ``MailboxFolderNameGuard`` for folder names
- ``Pop3MessageNumberGuard`` for POP3 commands

Event redaction
---------------

Mailbox command events are redacted through ``MailboxCommandRedactor``.
Sensitive fields such as LOGIN/PASS/AUTH payloads are never emitted in clear.

Transport security
------------------

Recommended production defaults:

- SMTP ``StartTlsRequired`` or ``Ssl``
- IMAP ``Ssl`` or ``StartTlsRequired``
- avoid plaintext POP3 where possible

Attachment safety
-----------------

Use ``ReceivedAttachment::safeFilename()`` before persisting inbound filenames
from untrusted sources.
