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

Observability and data minimization
-----------------------------------

Default protocol events are intentionally less detailed than caller-facing
``CommunicationResult`` diagnostics.

- HTTP event headers redact authorization, proxy authorization, cookies, API
  keys, auth tokens, and TalkingBytes webhook signatures.
- HTTP and webhook failure events emit stable failure categories instead of raw
  transport error strings.
- Webhook events never include signing secrets, signature values, or payload bodies.
- gRPC events emit method/status/count information and may include a local
  exception class, but not metadata values, request bodies, exception messages,
  traces, or remote wire exception details.
- Email lifecycle/logging events use recipient counts and stable failure
  categories; subjects, bodies, addresses, raw transport errors, and arbitrary
  transport metadata are not copied into default observability.
- Spool receiver events do not expose absolute paths or parsed subjects.
- Mailbox authentication commands redact both usernames and credentials.

Rich caller-facing errors remain available through returned result/response
objects where the protocol API documents them. Apply application logging policy
before recording those values.
