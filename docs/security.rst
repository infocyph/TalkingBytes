Security
========

Defaults
--------

- TLS verification is enabled by default.
- Redirects are disabled by default in HTTP requests.
- Header and URL validation prevents injection primitives.

HTTP safeguards
---------------

- host allow/block controls and private/reserved network blocking
- strict private-network mode disables inherited proxies and rejects explicit proxies
- redirect destination validation plus cross-origin credential stripping
- per-hop cookie provenance and opt-in parent-domain cookie policy
- response/download/upload size limits and atomic download publication
- redaction of built-in and explicitly marked credential headers/query keys

Webhook safeguards
------------------

- HMAC signature verification with ``hash_equals``
- v2 signatures bind timestamp, event, delivery ID, and exact raw body
- timestamp tolerance window checks
- replay claims cover the remaining signature window plus clock-correction budget
- replay-store failures are fail closed
- event payload redaction for signature/body/secret

Email safeguards
----------------

- mailbox command redaction (LOGIN/PASS/AUTH sensitive values)
- parser limits for message/header/multipart/attachment boundaries
- attachment filename sanitization
- IMAP/POP3 command guards for UID/part/message number validation
- IMAP move fallback uses UID-scoped expunge or fails before destructive mutation
- spool consumption uses an atomic ownership claim; ``peek()`` remains non-destructive
- DKIM verification enforces canonicalization, key policy, and DNS key semantics

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
