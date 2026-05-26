Security
========

Defaults
--------

- TLS verification is enabled by default.
- Redirects are disabled by default in HTTP requests.
- Header and URL validation prevents injection primitives.

HTTP safeguards
---------------

- host allow/block controls
- private/reserved network blocking
- redirect destination validation
- response/download/upload size limits
- redaction of auth-like headers and query secrets

Webhook safeguards
------------------

- HMAC signature verification with ``hash_equals``
- timestamp tolerance window checks
- replay protection hook via ``WebhookReplayStore``
- event payload redaction for signature/body/secret

Email safeguards
----------------

- mailbox command redaction (LOGIN/PASS/AUTH sensitive values)
- parser limits for message/header/multipart boundaries
- attachment filename sanitization
- IMAP/POP3 command guards for UID/part/message number validation

Operational guidance
--------------------

- Keep ``STARTTLS required`` for SMTP/IMAP in production.
- Use explicit limits for large mailbox scans and inbound parser workloads.
- Treat incoming authentication headers (SPF/DKIM/DMARC) as signal, not trust-on-first-use.
