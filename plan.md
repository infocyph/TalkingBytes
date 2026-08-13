# TalkingBytes — Final Next-Major Release Engineering Draft

## Release Policy

This is the final engineering specification for the next major release of `infocyph/talkingbytes`.

### Breaking Changes

Backward compatibility is **not required**.

Prefer:

```text
correctness
→ security
→ protocol compliance
→ performance
→ scalability
→ API clarity
→ compatibility
```

Existing APIs may therefore be redesigned where necessary.

### Feature Preservation

This release is **not intended to drop TalkingBytes capabilities**.

Existing supported/intended capabilities should remain and be improved.

If an existing implementation or API is:

- insecure;
- misleading;
- incomplete;
- inefficient;
- architecturally incorrect;

replace/redesign it rather than unnecessarily removing the underlying capability.

### PHPForge

Keep exactly:

```json
"infocyph/phpforge": "dev-main@dev"
```

Do not replace it with a tagged PHPForge version.

### Runtime Dependency Policy

Keep TalkingBytes lightweight.

Do not add ArrayKit, Intermix, CacheLayer, DBLayer, Omnibus, or another runtime dependency merely to replace small internal utilities.

Add a runtime dependency only where it provides substantial functionality that TalkingBytes should not reasonably implement itself.

---

# 1. Product Direction

TalkingBytes should be a **protocol-aware PHP communication toolkit**.

Its four primary communication domains are:

```text
Email
HTTP
Webhook
gRPC
```

They should share only genuinely common infrastructure.

Do not force every protocol through a universal communication abstraction.

---

# 2. Protocol Responsibility

## 2.1 Email

Email is a complete **inbound + outbound mail communication system**.

### Outbound

```text
Application
    ↓
EmailMessage
    ↓
Prepared MIME representation
    ↓
DKIM/signing if configured
    ↓
Transport
    ├── SMTP
    ├── sendmail
    ├── PHP mail()
    ├── spool
    ├── log
    ├── null
    └── fake
```

TalkingBytes should continue supporting:

- From/To/Cc/Bcc;
- Reply-To/Sender/Return-Path;
- subject;
- text;
- HTML;
- MIME alternatives;
- attachments;
- inline attachments;
- templates;
- DSN;
- headers;
- SMTP;
- sendmail;
- PHP `mail()`;
- spool;
- logging;
- DKIM;
- retries;
- fallbacks;
- rate limits;
- testing/fakes.

### Inbound

```text
Mail source
    ├── IMAP
    ├── POP3
    └── spool
        ↓
Raw RFC822 message
        ↓
Parser
        ├── headers
        ├── MIME
        ├── transfer decoding
        ├── charset decoding
        └── attachment extraction
        ↓
ParsedEmail
        ├── body
        ├── attachments
        ├── DKIM
        ├── Authentication-Results
        ├── DSN
        └── bounce classification
```

TalkingBytes should continue supporting the full inbound chain.

Do not reduce Email to only an SMTP sender.

---

# 3. HTTP Responsibility

HTTP remains the first-class **outbound HTTP client**.

Primary flow:

```text
Application
    ↓
HttpClient
    ↓
HttpRequest
    ↓
Request preparation
    ↓
Security validation
    ↓
CurlTransport / CurlMultiTransport
    ↓
HTTP peer
    ↓
HttpResponse
```

Primary use cases:

- REST APIs;
- external APIs;
- service integrations;
- authenticated APIs;
- uploads;
- downloads;
- multipart;
- concurrent requests;
- signed requests;
- API clients.

TalkingBytes HTTP should **not become an HTTP web framework/server**.

Do not add unrelated:

- controllers;
- routing;
- web application middleware;
- HTTP server lifecycle;
- framework request handling.

---

# 4. Webhook Responsibility

Webhook remains specialized HTTP communication for:

```text
asynchronous callbacks
+
signed event delivery
+
verified event reception
```

Outbound:

```text
WebhookMessage
→ delivery identity
→ signature
→ HTTP request
→ retry
→ result
```

Inbound:

```text
raw body + headers
→ signature validation
→ timestamp validation
→ replay claim
→ payload decoding
→ WebhookEvent
```

Webhook should reuse the HTTP transport where appropriate without duplicating the HTTP client architecture.

---

# 5. gRPC Responsibility

gRPC should be a first-class **bidirectional service-to-service communication module**.

It is particularly suited to internal services and microservices controlled by the same architecture.

TalkingBytes should support both sides.

## Outbound

```text
Service A
    ↓
GrpcClient
    ↓
GrpcRequest
    ↓
metadata/deadline/policy
    ↓
native/generated gRPC client
    ↓
Service B
```

## Inbound

```text
native gRPC runtime
    ↓
TalkingBytes inbound adapter/dispatcher
    ↓
GrpcInboundRequest
    ↓
application handler
    ↓
GrpcInboundResponse
    ↓
native runtime
```

gRPC should therefore remain:

```text
client
+
inbound dispatcher/adapter
+
request/response models
+
streaming
+
metadata
+
deadlines
+
retry
+
testing
```

Do not reduce it to an outbound client.

---

# 6. Preferred Communication Roles

Recommended architectural usage:

```text
Internal service RPC
    → gRPC

External/general APIs
    → HTTP

Asynchronous callback integrations
    → Webhook

Mail infrastructure / users
    → Email
```

gRPC should generally be preferred for controlled internal microservices where:

- contracts are shared;
- protobuf/generated classes are acceptable;
- low communication overhead matters;
- persistent HTTP/2 connections help;
- streaming is useful;
- typed status/deadline semantics are valuable.

HTTP remains preferable for broad interoperability and third-party APIs.

Do not try to make one replace the other universally.

---

# 7. gRPC Capability Model

Preserve/support all meaningful RPC modes.

## Unary

```text
request → response
```

## Server Streaming

```text
one request
→ many responses
```

## Client Streaming

```text
many requests
→ one response
```

## Bidirectional Streaming

```text
many requests
↔
many responses
```

Streaming must remain incremental and bounded.

Do not convert large/infinite streams into arrays internally.

---

# 8. gRPC Runtime Boundary

TalkingBytes should **not implement the gRPC wire/network stack**.

Leave:

```text
HTTP/2
protobuf framing
socket listening
native channel management
```

to:

- `ext-grpc`;
- `grpc/grpc`;
- generated stubs;
- appropriate native/runtime integrations.

TalkingBytes owns:

- application-facing request/response objects;
- method dispatch;
- native adapters;
- metadata;
- deadlines;
- retry policy;
- error/status mapping;
- streaming abstraction;
- observability;
- testing.

Generated `.proto` classes remain external:

```text
.proto
→ protoc
→ generated PHP
→ TalkingBytes integration
```

---

# 9. Core Architecture Redesign

## 9.1 Shrink `Core`

Core should contain only genuinely shared infrastructure.

Target conceptually:

```text
Core/
├── Event/
├── Result/
└── Support/
```

Do not place HTTP- or gRPC-specific concepts in Core merely to appear transport-neutral.

---

# 10. Remove `CommunicationRequest`

Current protocol requests already exist:

```text
HttpRequest
GrpcRequest
```

Wrapping them inside a generic:

```php
CommunicationRequest
```

creates:

- additional allocations;
- `mixed` payloads;
- duplicated options;
- duplicated headers;
- runtime `instanceof`;
- weaker static analysis.

For the next major:

```text
HttpRequest → HTTP pipeline
GrpcRequest → gRPC pipeline
```

directly.

Keep `CommunicationResult` if its shared result convention remains valuable.

Do not replace `CommunicationRequest` with another generic envelope under a new name.

---

# 11. Protocol-Specific Middleware

Move protocol-aware policies to protocol modules.

For example:

```text
Http/Middleware/
Grpc/Middleware/
```

HTTP-specific:

- authentication;
- headers;
- HTTP retry;
- HTTP idempotency;
- HTTP signing.

gRPC-specific:

- metadata;
- RPC deadline;
- RPC retry;
- RPC idempotency.

Only genuinely generic mechanics belong in Core.

---

# 12. HTTP P0 — Secure Redirect Handling

Do not rely on cURL automatic redirect following when destination security must be enforced.

Current effective pattern can become:

```text
validated public URL
→ redirect
→ private destination contacted by cURL
→ destination rejected afterward
```

That is too late.

Implement TalkingBytes-owned redirect processing:

```text
validate current destination
→ execute one request
→ receive redirect
→ resolve Location
→ validate next destination
→ execute next request
```

Validate **every redirect before connection**.

Cover:

- absolute redirects;
- relative redirects;
- scheme-relative redirects;
- loops;
- maximum redirects;
- changed host;
- changed port;
- HTTPS → HTTP;
- private destination;
- blocked host;
- allow-listed host.

---

# 13. HTTP P0 — Remove Security-Bypassing cURL Options

Do not allow unrestricted public:

```php
HttpRequest::option(CURLOPT_..., ...)
```

to override TalkingBytes-owned security/transport behavior.

Arbitrary overrides can affect:

- URL;
- TLS;
- redirect handling;
- callbacks;
- response limits;
- upload handling;
- proxy handling.

Replace arbitrary cURL configuration with typed supported APIs.

Remove/rework:

```text
CurlOptions::additional
```

Do not expose a generic unsafe escape hatch unless an unavoidable real-world requirement appears.

---

# 14. HTTP P0 — DNS Rebinding / TOCTOU

Avoid:

```text
security DNS lookup
→ validation
→ cURL performs independent DNS lookup
```

Resolve, validate and pin the destination where strict network protection is enabled.

The actual connection should use an address that TalkingBytes already validated.

Run the process again for every redirect.

---

# 15. HTTP Private-Network Policy

`blockPrivateNetworks()` should protect against more than RFC1918.

Reject relevant non-public/special-use ranges including:

- loopback;
- private;
- link-local;
- shared address space;
- multicast;
- unspecified;
- reserved;
- documentation/special-use networks;
- IPv6 equivalents.

Define the policy as effectively:

```text
globally routable destinations only
```

when strict protection is enabled.

---

# 16. HTTP Proxy Security

Explicitly define the interaction between:

```text
proxy configuration
+
private-network blocking
```

A remote proxy may perform DNS resolution independently.

Do not promise strict destination protection when TalkingBytes cannot control or verify proxy-side routing.

Either:

- support a proxy-aware enforcement design;
- reject incompatible combinations;
- or require explicit acknowledgement.

---

# 17. HTTP Configuration Precedence

Freeze this rule:

```text
explicit HttpRequest value
>
HttpClient configured default
>
library default
```

Apply consistently to:

- timeout;
- connect timeout;
- redirects;
- maximum redirects;
- TLS;
- CA;
- proxy;
- user-agent;
- response limits;
- upload/download limits.

Do not detect "unset" by comparing values to library defaults.

Represent explicit vs unset state correctly.

---

# 18. HTTP Header Precedence

Freeze:

```text
explicit request header
>
client default header
```

Do not let `withDefaultHeaders()` overwrite request-specific headers.

If a client-controlled mandatory header is needed, model it separately instead of calling it a default.

---

# 19. Deterministic HTTP Request Preparation

Request mutation must not depend on fluent configuration order.

Required conceptual order:

```text
1. resolve client defaults
2. finalize request query
3. finalize body
4. merge default/request headers
5. apply ordinary authentication
6. apply cookies
7. establish idempotency identity
8. finalize canonical URL/body
9. sign
10. transport
```

After signing, nothing that participates in the signature may mutate.

---

# 20. HTTP Retry Safety

Default automatic retry should be safe.

Naturally retryable/idempotent methods may include:

```text
GET
HEAD
OPTIONS
PUT
DELETE
```

For:

```text
POST
PATCH
```

require either:

- a stable idempotency key;
- explicit application opt-in.

Do not merely document that retries should be used carefully.

Enforce sensible behavior.

---

# 21. Stable Idempotency

A logical request must use exactly one idempotency key across all attempts.

```text
attempt 1
=
attempt 2
=
attempt N
```

Generate it before entering the retry loop.

It must not depend on middleware registration order.

---

# 22. Retry Policy Redesign

Prefer stateless retry decisions over policy objects carrying hidden per-attempt mutable state.

Conceptually:

```php
RetryDecision {
    bool retry;
    int delayMs;
}
```

Evaluate using a retry context.

Policy evaluation should be deterministic.

---

# 23. Retry Arithmetic Safety

All exponential/backoff calculations must be bounded.

Prevent:

```text
INF
NAN
integer overflow
float overflow
negative delays
unbounded Retry-After
```

before calling `usleep()`.

Set sensible caps for:

- attempts;
- delay;
- Retry-After.

---

# 24. HTTP Repeatable Upload Sources

Retryable uploads need repeatable sources.

Suitable sources:

```text
file path
seekable stream
source factory
bounded internal spool
```

Do not automatically retry an already-consumed non-seekable stream.

For non-seekable streams:

- reject;
- or explicitly spool once within a configured limit.

---

# 25. HTTP Multipart Ownership

All internally generated temporary multipart files must have explicit ownership.

Clean them in `finally`.

Do not leave temporary files behind from:

- `addData()`;
- `addStream()`;
- failed requests;
- exceptions;
- retries.

Do not implement stream multipart as:

```text
stream
→ complete PHP string
→ temp file
```

for large input.

Use incremental copying.

---

# 26. Avoid Duplicate HTTP Body Serialization

Signing, validation and transport should operate on one resolved body representation.

Avoid:

```text
serialize for signature
→ serialize again for cURL
```

For JSON/form/raw payloads, prepare once.

Define multipart signing semantics explicitly.

---

# 27. Reject URL Userinfo

Reject URLs such as:

```text
https://user:password@example.com
```

Require dedicated authentication APIs.

This avoids:

- credential leakage;
- URL reconstruction ambiguity;
- log exposure.

---

# 28. Preserve Query Semantics

Do not round-trip arbitrary URLs through `parse_str()`.

Preserve:

```text
?a=1&a=2
```

and ordered duplicate query entries.

Introduce an ordered query representation where needed.

---

# 29. HTTP Response Headers

Treat HTTP header names case-insensitively.

These must be semantically identical:

```text
Set-Cookie
set-cookie
SET-COOKIE
```

Preserve duplicate header values individually.

Especially preserve multiple `Set-Cookie` fields.

---

# 30. CookieJar Hardening

Fix:

## `Max-Age`

`Max-Age` must override `Expires` regardless of attribute order.

## Domain

Do not claim browser-equivalent domain-cookie safety without a public-suffix strategy.

Choose a conservative policy.

Possible direction:

- host-only by default;
- explicit domain-cookie policy;
- optional PSL support if justified.

Keep:

- path-boundary validation;
- domain-origin validation;
- cookie capacity bounds.

---

# 31. HTTP Config Parsing

Avoid dangerous casts such as:

```php
(bool) 'false'
```

Use strict configuration parsing.

Invalid security-critical values should preferably throw instead of silently becoming defaults.

---

# 32. CurlMulti Cleanup

Every path must clean:

- easy handles;
- multi handle;
- upload handles;
- download temp resources;
- multipart temp files.

Use outer `finally`.

Listener/observer exceptions must not leak resources.

---

# 33. CurlMulti Status Handling

Explicitly handle non-success:

```php
CURLM_*
```

results.

Do not leave active requests with ambiguous result states.

---

# 34. CurlMulti Select Handling

Handle:

```php
curl_multi_select() === -1
```

using a bounded small sleep/backoff.

Avoid CPU spinning.

---

# 35. Pool Fail-Fast Semantics

If `failFast` does not actually cancel active requests, either:

- implement real fail-fast cancellation;
- or rename/document it accurately.

Do not promise stronger behavior than provided.

---

# 36. HTTP Streaming Preserve

Keep the strong streamed-download model:

```text
temporary destination
→ bounded streaming
→ atomic finalize
```

Also define:

- existing-target replacement;
- file permissions;
- flushing;
- cleanup on failure.

---

# 37. Email P0 — Prepared Email Model

This should be one of the largest architectural changes.

Introduce an internal prepared/frozen email representation such as:

```text
PreparedEmail
```

or:

```text
MimePlan
```

Exact naming is implementation-defined.

Preparation should freeze:

- Date;
- Message-ID;
- MIME boundaries;
- content types;
- transfer encodings;
- headers;
- body sources;
- attachment sources;
- size information where available.

Flow:

```text
EmailMessage
→ prepare once
→ optional DKIM
→ transport exact prepared message
```

---

# 38. DKIM Must Sign Exact Wire Representation

Do not:

```text
build A
→ DKIM sign A
→ rebuild B
→ transmit B
```

because generated:

- Date;
- Message-ID;
- MIME boundaries;

may differ.

Required invariant:

```text
DKIM signed bytes
=
transmitted bytes
```

for the canonicalized fields/body.

---

# 39. Stable Email Identity

For one logical send:

```text
wire Message-ID
=
reported Message-ID
=
DKIM Message-ID
```

Generated `Date` must likewise remain stable.

Mime boundaries must not regenerate on retry/fallback.

---

# 40. Remove Full Pre-Render SMTP Inspection

Do not fully encode/render an email merely to discover:

- size;
- SMTPUTF8 requirement;
- 8BITMIME requirement;
- content characteristics.

Use the prepared plan.

Base64 size can be calculated from source size mathematically where available.

---

# 41. Repeatable Email Attachment Sources

Every attachment source involved in:

- DKIM;
- retry;
- fallback;
- inspection;
- send;

must be repeatable.

Suitable source concepts include:

```text
data
file
seekable stream
stream factory
bounded spool
```

Keep the internal class count minimal.

---

# 42. Non-Seekable Email Streams

Do not silently treat arbitrary resources as retry-safe.

For `attachStream()`:

- require seekability;
- accept a source factory;
- or explicitly spool a non-seekable stream within limits.

Do not let inspection exhaust the stream and then send an empty attachment.

---

# 43. Stream Ownership

Document and enforce:

```text
who owns the resource?
who closes it?
where does reading begin?
can it be replayed?
```

TalkingBytes must not unexpectedly close caller-owned resources.

Internally opened resources must always be closed.

---

# 44. Stream Size

Allow a known size to be supplied where appropriate.

Use it for:

- SMTP SIZE;
- planning;
- early rejection.

Always validate actual bytes while reading.

---

# 45. Email True Streaming

Target:

```text
prepared MIME plan
→ emit headers
→ emit body parts
→ encode attachments incrementally
→ transport writer
```

Avoid complete MIME body materialization into `php://temp` followed by another read.

---

# 46. Line Ending Normalization

Do not normalize CR/LF independently for arbitrary chunks.

A `\r\n` sequence can cross chunk boundaries.

Either:

- produce canonical CRLF upstream;
- or use a stateful normalizer.

---

# 47. `attachData()` Copies

After correctness is established, optimize `attachData()` to avoid unnecessary complete temporary stream copies.

Process large data strings incrementally where useful.

Benchmark before micro-optimizing.

---

# 48. Structural Email Headers

Prevent public arbitrary custom headers from overriding protocol-owned structural headers such as:

```text
From
To
Cc
Bcc
Date
Message-ID
MIME-Version
Content-Type
Content-Transfer-Encoding
DKIM-Signature
```

Dedicated APIs should own them.

In particular, a custom `Bcc` header must never defeat envelope-only BCC behavior.

---

# 49. Reply-To Behavior

Do not implicitly create:

```text
Reply-To = From
```

when setting From.

Mail clients already fall back to From when Reply-To is absent.

Use:

```text
from()
```

only for From.

Use:

```text
replyTo()
```

only when explicitly requested.

---

# 50. Outbound Header Bounds

Add:

```text
max total header bytes
max header fields
max header field bytes
max physical line bytes
```

Outbound folding must remain protocol-safe.

Reject unreasonably long unbreakable tokens.

---

# 51. SMTP Local Domain Validation

`localDomain` enters EHLO/HELO commands.

Reject:

- CR;
- LF;
- NUL;
- control characters;
- invalid whitespace;
- invalid/oversized identity forms.

Prevent SMTP command injection.

---

# 52. SMTP Authentication Security

Do not send credentials over plaintext accidentally.

Reject:

```text
SmtpSecurity::None + credentials
```

and:

```text
StartTlsOptional
+
STARTTLS unavailable
+
credentials
```

Prefer no insecure-authentication override unless a genuine consumer requirement arises.

---

# 53. SMTP AUTH Capability

Do not automatically guess `AUTH LOGIN` when the server does not advertise a supported AUTH method.

Fail explicitly.

---

# 54. SMTP Response Bounds

Bound:

- line size;
- multiline count;
- total response bytes.

Reject truncated/overlong lines.

---

# 55. SMTP Deadlines

Socket read timeout alone is insufficient.

Implement an overall command/operation deadline.

A slow-drip server must not keep an operation alive indefinitely.

---

# 56. SMTP TLS

Use explicit secure TLS stream settings:

```text
verify_peer
verify_peer_name
peer_name
SNI
secure crypto policy
```

Expose CA/client certificate settings where useful.

Do not add an easy generic insecure switch.

---

# 57. SMTP Transcript Redaction

Keep the existing principle:

- auth secret omitted;
- DATA represented safely;
- no credential leakage.

Future debug features must preserve this.

---

# 58. IMAP/POP3 Credential Guards

Reject protocol command injection through username/password.

At minimum reject:

```text
CR
LF
NUL
ASCII controls
```

before command construction.

---

# 59. IMAP/POP3 Authentication Security

Do not silently send credentials over plaintext.

Apply equivalent secure-auth rules to mailbox protocols.

---

# 60. IMAP Literal Bounds

Validate advertised literal size **before allocation/read**.

Do not read a giant server-declared literal and only later apply `EmailLimits`.

---

# 61. IMAP Response Bounds

Bound:

- line bytes;
- number of response lines;
- total response bytes;
- literal bytes.

---

# 62. POP3 Multiline Bounds

Bound multiline responses while reading.

Do not first accumulate an arbitrary message and only afterward give it to the parser.

---

# 63. Protocol Line Truncation

A bounded `fgets()` result that reached the line limit without termination should not be accepted as a complete valid protocol line.

Reject explicitly.

---

# 64. Mailbox Command Deadlines

Apply overall deadlines to IMAP/POP commands.

Account for IDLE/watch behavior separately.

---

# 65. Mailbox Limits

Propagate one coherent limit policy through:

```text
transport
→ raw fetch
→ parsed fetch
→ attachments
→ MIME parser
```

Do not instantiate unrelated default `EmailLimits` deep inside operations.

---

# 66. Mailbox TLS

Configure certificate verification explicitly for IMAP/POP.

Include:

- hostname verification;
- SNI;
- CA configuration when necessary.

---

# 67. MIME Limits During Parsing

Current safety limits should reject complexity **while constructing** the MIME tree.

Track:

- current depth;
- total parts;
- decoded bytes;
- attachment bytes/count;

during parsing.

Do not fully construct pathological data before rejecting it.

---

# 68. Incremental Decoding Limits

Base64/quoted-printable/body decoding should enforce byte bounds incrementally.

Do not decode a huge payload and reject afterward.

---

# 69. Header Limit Semantics

Differentiate:

```text
maxHeaderBytes
maxHeaderFields
maxHeaderLineBytes
```

Folded continuation lines are not separate header fields.

---

# 70. Exact Raw Email Preservation

`ParsedEmail::raw` should represent exact received bytes.

Do not normalize it before storage.

Normalized forms may be produced internally or stored separately.

Exact raw preservation is critical for authentication/forensics.

---

# 71. DKIM Raw Header Model

Preserve raw signed header fields in addition to parsed/unfolded values.

Conceptually:

```text
name
raw bytes
unfolded value
```

DKIM verification must use appropriate representation based on canonicalization.

---

# 72. DKIM Canonicalization

Correctly support/test:

```text
simple/simple
simple/relaxed
relaxed/simple
relaxed/relaxed
```

Use independently generated fixtures.

Do not validate only TalkingBytes-generated mail against TalkingBytes verification.

---

# 73. DKIM Required Headers

Ensure `From` participates in signed headers according to DKIM requirements.

Reject invalid signing configuration early.

---

# 74. DKIM Duplicate Header Semantics

Repeated names in:

```text
h=
```

must consume header occurrences from the bottom correctly.

Do not select the same final header repeatedly.

Signer and verifier must agree.

---

# 75. DKIM Algorithms

TalkingBytes currently exposes RSA-SHA256 and Ed25519-SHA256 concepts.

Because this release is not intended to drop capabilities:

- fully implement Ed25519-SHA256 signing/verification where the PHP/OpenSSL/runtime capability permits;
- detect unsupported runtime capability explicitly;
- do not claim successful Ed25519 support on environments unable to provide it.

RSA-SHA256 remains fully supported.

Do not leave an enum/API option that can only throw because implementation is missing.

---

# 76. Multiple DKIM Signatures

Support messages containing more than one:

```text
DKIM-Signature
```

Provide per-signature results and convenient:

```text
any valid
all results
```

semantics.

This supports forwarding, transitions and key rotation.

---

# 77. DKIM Resource Bounds

Bound untrusted:

- signature header bytes;
- tag count;
- `d=`;
- `s=`;
- `h=`;
- `b=`;
- `bh=`.

Prevent excessive DNS/crypto/parser work.

---

# 78. DKIM Key Records

Handle important key-record semantics deliberately:

- `v=`;
- `k=`;
- `p=`;
- revoked/empty `p=`;
- unsupported key types.

Do not blindly wrap every `p=` value into a public key.

---

# 79. DKIM Optional Tags

Explicitly support or reject tags such as:

```text
l=
x=
```

Do not silently appear RFC-complete while ignoring security-relevant semantics.

---

# 80. DKIM Cache TTL

`CachedDkimPublicKeyResolver` must not cache keys or missing records forever.

Use bounded:

- positive TTL;
- negative TTL;
- capacity.

Do not add CacheLayer solely for this.

---

# 81. DKIM Clock

Use the internal Clock abstraction for signing timestamps.

This improves deterministic tests.

---

# 82. Spool Atomicity

Keep:

```text
temporary/incomplete file
→ complete write
→ atomic rename
```

as the spool publishing model.

---

# 83. Spool Size Precheck

Check filesystem size before loading an entire `.eml` with `file_get_contents()`.

Then enforce bounded read and parser limits.

---

# 84. Spool Directory Scanning

Avoid:

```text
glob entire directory
→ sort
→ choose one
```

for every message in `receiveMany()`.

Use one bounded scan/batch operation where possible.

---

# 85. Spool Producer Contract

Document that producers must publish complete messages atomically.

Receiver should consume only finalized extensions.

---

# 86. Spool Permissions

Use conservative filesystem permission behavior for:

- mail contents;
- metadata;
- failed messages;
- logs.

Respect secure process `umask`.

---

# 87. Failure Sidecars

Bound and sanitize failure metadata.

Do not persist arbitrarily large exception messages or control characters.

Avoid secrets.

---

# 88. Spool Directory Validation

Prevent pathological overlaps between:

```text
source
processing
success
failure
```

directories.

---

# 89. Log Transport

Stream large raw messages to the log file instead of always creating a complete PHP string first.

---

# 90. Sendmail Output Bounds

Drain process stdout/stderr to prevent blocking, but retain only bounded diagnostic data.

---

# 91. Sendmail Timeout

After timeout:

```text
terminate
→ grace
→ force termination if needed
→ close
```

Do not allow `proc_close()` to block indefinitely.

---

# 92. Email Event Redaction

Do not blindly publish complete transport metadata in events.

Use a centralized safe metadata allow-list/redactor.

---

# 93. Observability Must Not Break Delivery

An event listener/logger exception must not turn successful:

- SMTP;
- sendmail;
- spool;
- HTTP;
- webhook;
- gRPC;

communication into failure by default.

---

# 94. IMAP Parser Corpus

Expand captured/pathological fixtures for:

- quotes;
- escaped strings;
- NIL;
- nested ENVELOPE;
- address groups;
- literals;
- multiple FETCH values;
- `BODY[]`;
- `BODY.PEEK[]`;
- partial sections;
- nested BODYSTRUCTURE;
- UTF7-IMAP;
- delimiters;
- untagged responses;
- malformed literal declarations;
- IDLE.

Keep the native implementation rather than replacing it with `ext-imap`.

---

# 95. Webhook Atomic Replay

Replace non-atomic:

```text
seen()
remember()
```

with atomic claim semantics.

Conceptually:

```php
claim(namespace, deliveryId, ttl): bool
```

`true`:

```text
first claimant
```

`false`:

```text
already claimed
```

Support Redis/database implementations cleanly without forcing those dependencies into TalkingBytes.

---

# 96. Replay Namespace

Replay identity should include an endpoint/provider/application namespace.

Do not globally identify deliveries solely by caller-supplied delivery ID.

---

# 97. Replay Semantics Documentation

Clearly state:

```text
replay claim
≠
exactly-once business processing
```

TalkingBytes prevents duplicate acceptance within the configured replay model.

Application processing may still fail after acceptance.

---

# 98. Multiple Webhook Signatures

Support:

```text
t=timestamp,v1=signature1,v1=signature2
```

Verification succeeds when an allowed signature matches a configured secret.

---

# 99. Webhook Secret Rotation

Support bounded active secrets such as:

```text
current
previous
```

without requiring consumers to build duplicate receiver pipelines.

---

# 100. Webhook Signature Bounds

Bound:

- signature header bytes;
- tag segments;
- signature count;
- malformed/invalid hex input.

---

# 101. Webhook Payload Bounds

Add/configure a maximum body size.

Reject overly large input before expensive:

- signature work;
- JSON decoding;

where possible.

---

# 102. Webhook Identifier Guards

Reject relevant ASCII control characters in event/delivery values.

Keep sensible length bounds.

---

# 103. One Webhook Retry Layer

Do not allow:

```text
WebhookSender retries
×
HttpClient retries
```

to multiply network attempts invisibly.

Webhook delivery should have one authoritative retry engine.

---

# 104. Webhook Per-Attempt Signature Time

For every actual network attempt:

```text
same delivery ID
+
new timestamp
+
new signature
+
correct attempt number
```

Do not sign once and retry hours later with a stale timestamp.

---

# 105. Stable Webhook Delivery Identity

Keep one logical delivery ID across attempts.

This existing behavior is correct.

---

# 106. Webhook Fake Parity

Fake sender must validate the same message readiness requirements as production sender.

A fake should not report success for input the real transport would reject.

---

# 107. Webhook User-Agent

Remove hardcoded:

```text
TalkingBytes/1.0
```

Use a correct stable version strategy.

Avoid manually duplicating package version across the codebase.

---

# 108. Event Architecture

Remove global static dispatcher state as the main mechanism.

Clients/senders should receive an `EventDispatcher`.

Default:

```text
NullEventDispatcher
```

keeps disabled observability lightweight.

---

# 109. Event Failure Policy

Default dispatch should be best-effort.

Observability is secondary to communication correctness.

If strict/fail-fast event handling is ever needed, make it explicit.

---

# 110. Resource Cleanup Before Event Failure

All:

- sockets;
- cURL handles;
- process resources;
- temporary files;
- streams;

must be cleaned using `finally` independently of listener behavior.

---

# 111. Remove Redundant Email Event Wrappers

After the common event architecture is established, remove redundant implementation wrappers that provide no actual Email-specific behavior.

This is an internal architecture cleanup, not a loss of email-event capability.

---

# 112. Event Documentation

Current docs referencing nonexistent/reset semantics must be aligned with the final actual API.

Do not document methods that do not exist.

---

# 113. Rate Limiter

Replace O(n) timestamp-filtering hot paths with a bounded algorithm such as token bucket.

Desired properties:

```text
O(1)-style hot path
bounded memory
monotonic timing
deterministic tests
```

Keep this process-local.

Do not force distributed persistence into TalkingBytes.

---

# 114. Circuit Breaker

Implement actual:

```text
Closed
→ Open
→ HalfOpen
→ Closed/Open
```

semantics.

After cooldown, permit only a limited probe—preferably one—to determine recovery.

Prevent a thundering herd after cooldown.

An enum is appropriate for circuit state.

---

# 115. Internal Clock

Introduce a small internal clock abstraction.

Use where time controls behavior:

- retry;
- webhook timestamps;
- replay;
- DKIM;
- rate limiter;
- circuit breaker;
- deadlines;
- command timeouts.

Use monotonic time for elapsed durations where appropriate.

---

# 116. Internal Sleeper

Centralize blocking sleep/backoff behavior.

Allows deterministic testing and consistent overflow-safe sleeps.

Keep it internal unless consumers genuinely require injection.

---

# 117. HTTP Signing Namespace

HTTP request signing is HTTP-specific.

Move generic-looking signing infrastructure under:

```text
Http/Signing/
```

or an equally clear HTTP namespace.

Webhook signing remains in Webhook.

Do not generalize unrelated protocols solely because both use HMAC.

---

# 118. HMAC Key Validation

Reject empty HMAC keys.

---

# 119. Signed Request Nonce/Timestamp

Custom nonce generators must produce:

```text
non-empty
bounded
header-safe
```

values.

Custom clocks must produce valid finite timestamps.

Extension hooks must not bypass normal security guards.

---

# 120. gRPC Internal Communication Role

Treat gRPC as the main TalkingBytes option for efficient internal RPC where appropriate.

Important benefits include:

- generated typed contracts;
- compact protobuf payloads;
- HTTP/2 multiplexing;
- persistent channels;
- deadlines;
- metadata;
- canonical status codes;
- streaming.

Do not market it as universally superior to HTTP.

Use it where its RPC model fits.

---

# 121. gRPC Inbound Capability

Keep inbound request handling first-class.

TalkingBytes should support:

```text
native/runtime request
→ inbound adapter
→ method dispatch
→ GrpcInboundRequest
→ handler
→ GrpcInboundResponse
```

This is an intentional package capability.

---

# 122. `GrpcServer` Naming

The current object acts more like an inbound dispatcher than a full socket/network gRPC server.

Because compatibility is not required, consider a clearer name such as:

```text
GrpcInboundDispatcher
```

This is a naming/architecture correction only.

**Do not remove inbound gRPC capability.**

---

# 123. gRPC Retry Exceptions

Do not automatically retry every thrown `Throwable`.

Never retry programming/application problems by default, including:

```text
TypeError
LogicException
invalid request
adapter contract errors
application handler errors
```

Only retry explicitly classified transient transport/runtime failures.

---

# 124. gRPC Retry Statuses

Use conservative retry defaults.

Do not assume statuses such as:

```text
Internal
Aborted
DeadlineExceeded
```

are universally retry-safe.

Retry requires both:

```text
transient condition
+
retry-safe RPC semantics
```

---

# 125. gRPC RPC Idempotency

gRPC method names do not automatically imply idempotency.

Require explicit method/request retry safety for automatic retries.

Do not accidentally retry:

```text
Charge
Transfer
CreateOrder
```

merely because a transport failure occurred.

---

# 126. Overall gRPC Deadline

A logical RPC deadline covers the entire operation.

Do not restart a full deadline on every retry.

Required:

```text
overall deadline
→ attempt
→ backoff consumes budget
→ remaining deadline
→ next attempt
```

Stop when the budget is exhausted.

---

# 127. gRPC Deadline Validation

Reject:

```text
NAN
INF
-INF
overflow
invalid negative values
```

before seconds/microseconds conversion.

Apply to:

- unary request;
- stream request;
- inbound request;
- deadline utilities.

---

# 128. Deadline Propagation

Support/enhance practical deadline propagation between internal services.

Example:

```text
Service A has 900 ms remaining
→ Service B receives remaining budget
→ Service B does not create a new independent 3-second budget
```

This is important for real microservice latency control.

---

# 129. gRPC Metadata

Keep first-class metadata support for:

- auth;
- correlation IDs;
- tracing;
- tenant context;
- service metadata;
- binary values.

Add bounds for:

```text
key count
key length
value length
total metadata bytes
```

---

# 130. gRPC Status Semantics

Preserve canonical gRPC status information.

Do not reduce protocol result handling to only:

```text
success / error string
```

`CommunicationResult` may wrap a protocol-specific `GrpcResponse`, but gRPC status remains authoritative.

---

# 131. gRPC Streaming Policy

Unary middleware and streaming middleware/policies are not automatically equivalent.

Make streaming behavior explicit.

Do not claim that a unary retry/timeout middleware applies to all streaming operations when it does not.

---

# 132. Streaming Retry Safety

A generator/client stream may be non-repeatable.

Do not automatically retry consumed client/bidi streams.

Retry requires an explicitly repeatable message source.

---

# 133. Streaming Backpressure

Do not buffer entire gRPC streams by default.

Process messages incrementally.

Allow consumer callbacks/iterators to control flow where supported by the native runtime.

---

# 134. gRPC Cancellation

Where supported by the native gRPC runtime, expose cancellation/abort semantics.

A caller abandoning work should be able to communicate that state to the peer.

Keep this within native adapter capabilities rather than implementing protocol internals.

---

# 135. Generated gRPC Adapter Validation

Current runtime duck-typing is flexible but failures should happen early.

Validate expected native call capabilities immediately when opening a:

- unary;
- server stream;
- client stream;
- bidi stream.

Do not fail halfway through communication where avoidable.

---

# 136. gRPC Method Identity

Normalize method names internally to canonical:

```text
/package.Service/Method
```

Avoid mismatches between method maps and requests.

Convenience input may accept variants if normalization is unambiguous.

---

# 137. gRPC Inbound Exception Privacy

Do not expose raw application exception messages to remote gRPC callers.

Map internal failures to:

```text
INTERNAL
+
safe external message
```

Send details only to local observability.

---

# 138. Configuration Philosophy

Prefer typed constructors as the main API.

Keep `fromArray()` where useful.

For array configuration:

- parse booleans strictly;
- validate enums strictly;
- reject malformed security settings;
- avoid surprising fallback.

Do not convert an explicit invalid configuration into another valid setting silently.

---

# 139. Composer Runtime

Keep runtime requirements lean.

Current direction remains appropriate:

```text
PHP 8.4+
ext-curl
ext-fileinfo
ext-openssl
```

Consider:

```json
"php": "^8.4"
```

if PHP 9 support is not explicitly tested.

Otherwise retain `>=8.4` only with an intentional future-major CI policy.

---

# 140. Optional Extensions

Keep optional capabilities optional where appropriate:

- `ext-grpc`;
- `grpc/grpc`;
- `ext-mbstring`;
- `ext-iconv`;
- `ext-imap`.

TalkingBytes native mail parsing/mailbox behavior should not unnecessarily depend on `ext-imap`.

---

# 141. PHPForge

Final `composer.json` requirement:

```json
"require-dev": {
    "infocyph/phpforge": "dev-main@dev"
}
```

Ensure repository, release branch and reviewed snapshot agree before tagging.

---

# 142. Do Not Add Unnecessary Infocyph Dependencies

Specifically do not add:

```text
ArrayKit
Intermix
CacheLayer
DBLayer
Omnibus
```

solely for:

- configuration parsing;
- simple maps;
- tiny caches;
- basic retry logic;
- internal collections.

Dependency-free core behavior remains a strength of TalkingBytes.

---

# 143. Documentation — Architecture

Update architecture docs to describe the actual final roles:

```text
Email
→ inbound + outbound

HTTP
→ outbound HTTP client

Webhook
→ outbound + inbound signed callbacks

gRPC
→ outbound + inbound service RPC
```

Do not call protocol-aware middleware universally transport-agnostic.

---

# 144. Documentation — HTTP Security

Document:

- manual redirect validation;
- pre-connection destination checks;
- DNS rebinding protection;
- private/special-use policy;
- proxy implications;
- no arbitrary cURL bypass.

---

# 145. Documentation — Retry

Document separately:

```text
HTTP retry
Webhook retry
gRPC retry
Email retry/fallback
```

Do not present all retry semantics as interchangeable.

Document:

- idempotency;
- repeatable streams;
- overall deadlines;
- maximum delays.

---

# 146. Documentation — Email Streaming

Only call a path streaming if large content is actually processed incrementally.

Document source repeatability and ownership.

---

# 147. Documentation — SMTP Authentication

Align docs with the real no-auth model.

If:

```text
credentials === null
```

means no SMTP AUTH, document exactly that.

Do not describe an enum value that does not exist.

---

# 148. Documentation — DKIM

Document:

- supported algorithms;
- runtime capability requirements;
- canonicalizations;
- multiple signatures;
- DNS key caching;
- validation bounds;
- key rotation behavior.

---

# 149. Documentation — Webhook Replay

Clearly state replay protection guarantees and limitations.

---

# 150. Documentation — gRPC

Clearly state:

- client capabilities;
- inbound dispatcher capabilities;
- streaming modes;
- deadlines;
- metadata;
- native runtime boundary.

Do not imply TalkingBytes itself implements an HTTP/2 gRPC network server if it does not.

---

# 151. Documentation — Events

Remove stale examples such as nonexistent event-bus reset APIs unless the final API actually implements them.

---

# 152. HTTP Test Matrix

Require tests for:

## Defaults

- explicit timeout beats client default;
- redirects;
- TLS;
- proxy;
- headers;
- limits.

## Preparation

- auth then signing;
- query auth + signing;
- stable idempotency;
- fluent-order independence.

## Query

- duplicate keys;
- ordered duplicates;
- fragments;
- key edge cases;
- userinfo rejection.

## SSRF

- localhost;
- IPv4 private;
- IPv6 private;
- special-use;
- DNS rebinding;
- redirect to private;
- redirect chains;
- allow/block;
- proxy policy.

## Retry

- safe method;
- unsafe method;
- idempotency;
- transport classification;
- Retry-After;
- overflow.

## Upload

- file;
- seekable stream;
- non-seekable stream policy;
- retry;
- multipart cleanup.

## Pool

- multi errors;
- select `-1`;
- cleanup after exception;
- fail-fast semantics.

## Headers/Cookies

- mixed-case duplicates;
- Set-Cookie;
- Max-Age order;
- domain behavior;
- path boundary.

---

# 153. Email Test Matrix

Require:

## Prepared Email

- Date stable;
- Message-ID stable;
- boundary stable;
- reported/wire ID equal;
- retries preserve representation;
- fallback preserves representation.

## Attachments

- file;
- data;
- seekable stream;
- non-seekable;
- retry;
- fallback;
- DKIM;
- size exact/over-limit;
- inline;
- 1/10/25 MB.

## DKIM

External fixtures for:

```text
simple/simple
simple/relaxed
relaxed/simple
relaxed/relaxed
```

plus:

- folds;
- duplicates;
- multiple signatures;
- required From;
- RSA;
- Ed25519 where available;
- invalid/revoked keys;
- cache expiry;
- oversized tags.

## MIME

- deep trees;
- too many parts;
- too many attachments;
- oversized decoding;
- malformed boundaries;
- nested MIME;
- exact raw preservation.

## SMTP

- EHLO injection;
- plaintext auth rejection;
- STARTTLS downgrade;
- unsupported AUTH;
- response limits;
- total deadline;
- transcript secrets.

## IMAP/POP

- credential injection;
- insecure auth;
- literal limits;
- response limits;
- overlong lines;
- command deadlines;
- TLS.

## Spool/Sendmail/Log

- atomic publication;
- size precheck;
- large directory;
- failure handling;
- sidecar limits;
- output flooding;
- timeout;
- large streaming logs.

---

# 154. Webhook Test Matrix

Require:

- valid HMAC;
- invalid HMAC;
- stale timestamp;
- future boundary;
- multiple `v1`;
- current secret;
- previous secret;
- invalid secret;
- oversized signature;
- oversized payload;
- malformed segments;
- identifier control characters;
- atomic concurrent replay claim;
- replay namespace;
- stable delivery ID;
- new timestamp per attempt;
- new signature per attempt;
- one retry layer;
- fake/real parity;
- redaction.

---

# 155. gRPC Test Matrix

Require:

## Unary

- success;
- status mapping;
- transient transport retry;
- programming error no retry;
- idempotent retry;
- unsafe retry rejected;
- total deadline.

## Deadline

- zero;
- negative;
- NaN;
- infinity;
- overflow;
- remaining deadline propagation.

## Metadata

- normal;
- duplicate;
- binary;
- invalid keys;
- control characters;
- count limits;
- value limits;
- total limits.

## Native Adapter

- missing method;
- invalid call shape;
- unary;
- server stream;
- client stream;
- bidi stream;
- trailers/status.

## Streaming

- server;
- client;
- bidi;
- non-repeatable stream retry rejection;
- incremental consumption;
- callback errors;
- cancellation where runtime supports it.

## Inbound

- known method;
- unknown method;
- handler success;
- handler exception;
- no raw exception leakage;
- event-listener behavior.

---

# 156. Adversarial Test Corpus

Maintain reusable corpora for:

## HTTP

- unusual IP forms;
- IPv6;
- redirects;
- query encodings;
- headers;
- cookies;
- hostile URLs.

## Email

- malformed MIME;
- deep MIME;
- huge boundaries;
- malformed encodings;
- duplicate headers;
- folded headers;
- charsets;
- DSNs;
- bounce messages;
- DKIM variations;
- IMAP transcripts;
- POP3 responses.

## Webhook

- malformed tags;
- duplicate tags;
- oversized tags;
- multiple secrets;
- invalid signatures.

---

# 157. Benchmark Expansion

Keep existing PHPBench coverage and add:

## HTTP

- request creation;
- HeaderBag;
- QueryParams;
- buildUrl;
- 0/1/5/10 middleware/policies;
- auth;
- signing;
- body serialization;
- response collection;
- multipart.

## Email

- plain build;
- HTML;
- multipart;
- parse;
- 1/10/25 MB attachment;
- prepared email;
- DKIM RSA;
- DKIM Ed25519 where available;
- cached/uncached verification.

## Webhook

- sign;
- parse;
- verify;
- multiple secrets;
- replay claim.

## gRPC

- request creation;
- metadata;
- unary adapter;
- policy overhead;
- deadline calculation.

## Resilience

- token bucket allow/reject;
- breaker closed/open/half-open.

---

# 158. End-to-End Performance Measurements

Especially benchmark actual large payload behavior.

## SMTP

Test:

```text
1 MB
10 MB
25 MB
```

Measure:

- wall time;
- peak PHP memory;
- bytes copied;
- disk spill.

## HTTP

Measure:

- large upload;
- multipart;
- large streamed download;
- concurrent downloads.

## Email Parser

Measure realistic:

- attachment-heavy mail;
- MIME nesting;
- malformed near-limit data.

---

# 159. Optimization Priority

Optimize in this order:

```text
remove repeated full payload work
→ remove repeated serialization
→ remove full-memory copies
→ remove repeated scans
→ bound allocations
→ reduce unnecessary hot-path objects
→ micro-optimize expressions
```

Do not increase architectural complexity for tiny benchmark wins.

---

# 160. Release Validation

Before tagging:

```text
composer validate --strict
PHPForge full CI
PHP lint
static analysis
style
unit tests
integration tests
security audit
benchmark comparison
documentation build
```

Also verify a production-style consumer install:

```bash
composer install --no-dev --classmap-authoritative
```

---

# 161. Documentation Gate

Run:

```bash
sphinx-build -W --keep-going -b html docs build/docs
```

Warnings fail the release.

Verify all documentation examples match the actual final API.

---

# 162. Optional Extension CI

Test meaningful combinations:

```text
mbstring present/absent
imap present/absent
iconv where available
grpc integration available
```

Graceful fallback behavior should actually be tested.

---

# 163. Implementation Order

## Phase 1 — Architecture

- [ ] Freeze four protocol roles.
- [ ] Preserve all existing intended capabilities.
- [ ] Remove `CommunicationRequest`.
- [ ] Introduce typed protocol pipelines.
- [ ] Move protocol-specific middleware.
- [ ] Redesign event injection.
- [ ] Add Clock/Sleeper.
- [ ] Clean signing namespace.

## Phase 2 — HTTP Security

- [ ] Remove unrestricted cURL options.
- [ ] Implement manual redirects.
- [ ] Resolve/validate/pin DNS.
- [ ] Strengthen private/special-use blocking.
- [ ] Define proxy policy.
- [ ] Reject URL userinfo.
- [ ] Add SSRF tests.

## Phase 3 — HTTP Semantics

- [ ] Fix client/request precedence.
- [ ] Fix default-header precedence.
- [ ] Replace lossy query parsing.
- [ ] Freeze request preparation order.
- [ ] Stable idempotency.
- [ ] Idempotency-aware retry.
- [ ] RetryDecision/stateless policy.
- [ ] Overflow-safe backoff.

## Phase 4 — HTTP Resources

- [ ] Repeatable upload source model.
- [ ] Multipart temp ownership.
- [ ] Incremental multipart spooling.
- [ ] Response header normalization.
- [ ] Cookie fixes.
- [ ] CurlMulti cleanup/status/select.
- [ ] Correct fail-fast semantics.

## Phase 5 — Prepared Email

- [ ] Introduce prepared representation.
- [ ] Freeze Date.
- [ ] Freeze Message-ID.
- [ ] Freeze MIME boundaries.
- [ ] Freeze encodings.
- [ ] Repeatable attachment sources.
- [ ] Remove pre-render inspection.
- [ ] Stream prepared content.

## Phase 6 — DKIM

- [ ] Exact-wire signing.
- [ ] Raw inbound header model.
- [ ] Correct canonicalizations.
- [ ] Required From.
- [ ] Duplicate header semantics.
- [ ] Complete RSA support.
- [ ] Complete Ed25519 support where runtime supports it.
- [ ] Multiple signatures.
- [ ] Key record validation.
- [ ] Resource limits.
- [ ] TTL cache.

## Phase 7 — Email Protocol Security

- [ ] SMTP EHLO guard.
- [ ] SMTP secure authentication.
- [ ] SMTP bounds/deadlines.
- [ ] SMTP TLS.
- [ ] IMAP credential guard.
- [ ] POP3 credential guard.
- [ ] secure mailbox authentication.
- [ ] IMAP literal bounds.
- [ ] POP3 response bounds.
- [ ] mailbox deadlines.
- [ ] mailbox TLS.

## Phase 8 — Parser / Spool / Process

- [ ] MIME limits during parse.
- [ ] incremental decoded limits.
- [ ] exact raw preservation.
- [ ] header limits.
- [ ] spool precheck.
- [ ] spool scan optimization.
- [ ] spool permissions.
- [ ] sidecar bounds.
- [ ] log streaming.
- [ ] sendmail output bound.
- [ ] sendmail timeout hardening.

## Phase 9 — Webhook

- [ ] Atomic replay claim.
- [ ] Replay namespace.
- [ ] Multiple signatures.
- [ ] Secret rotation.
- [ ] Signature limits.
- [ ] Payload limit.
- [ ] One retry layer.
- [ ] Per-attempt timestamp/signature.
- [ ] Fake parity.
- [ ] Version/User-Agent cleanup.

## Phase 10 — gRPC

- [ ] Keep outbound client.
- [ ] Keep/enhance inbound handling.
- [ ] Clarify/rename inbound dispatcher.
- [ ] Preserve unary.
- [ ] Preserve server streaming.
- [ ] Preserve client streaming.
- [ ] Preserve bidi streaming.
- [ ] Transient error classification.
- [ ] RPC idempotency.
- [ ] Overall deadline.
- [ ] Deadline propagation.
- [ ] Deadline finite checks.
- [ ] Metadata limits.
- [ ] Streaming policy behavior.
- [ ] Streaming backpressure.
- [ ] Cancellation integration.
- [ ] Native-call validation.
- [ ] Method normalization.
- [ ] Error privacy.

## Phase 11 — Resilience

- [ ] Token-bucket rate limiter.
- [ ] Closed/Open/HalfOpen breaker.
- [ ] Single probe recovery.
- [ ] Clock/Sleeper integration.

## Phase 12 — Quality

- [ ] Expand corpus tests.
- [ ] Expand benchmarks.
- [ ] Large-payload tests.
- [ ] Integration tests.
- [ ] Optional-extension matrix.

## Phase 13 — Docs / Release

- [ ] Rewrite architecture.
- [ ] Rewrite protocol roles.
- [ ] Rewrite security.
- [ ] Rewrite retry.
- [ ] Rewrite email streaming.
- [ ] Rewrite DKIM.
- [ ] Rewrite Webhook replay.
- [ ] Rewrite gRPC service communication.
- [ ] Fix stale APIs/examples.
- [ ] Sync Composer.
- [ ] Full PHPForge CI.
- [ ] Sphinx warnings-as-errors.
- [ ] Consumer-install test.
- [ ] Benchmark acceptance.
- [ ] Release.

---

# 164. Final Architecture Acceptance

The next major is architecturally complete only when:

- [ ] Email remains full inbound + outbound.
- [ ] HTTP remains a powerful outbound HTTP client.
- [ ] Webhook remains inbound + outbound callback communication.
- [ ] gRPC remains outbound + inbound service communication.
- [ ] gRPC unary remains supported.
- [ ] gRPC server streaming remains supported.
- [ ] gRPC client streaming remains supported.
- [ ] gRPC bidirectional streaming remains supported.
- [ ] Current functionality is not removed merely to simplify architecture.
- [ ] Unsafe functionality is redesigned rather than retained incorrectly.
- [ ] No universal generic request object weakens protocol types.
- [ ] Core contains only truly shared concerns.
- [ ] gRPC network/wire runtime remains delegated to native gRPC tooling.
- [ ] Internal services can communicate directly through gRPC without HTTP abstraction.
- [ ] HTTP remains suitable for external/general integrations.
- [ ] Webhook remains layered sensibly over HTTP.
- [ ] Email inbound/outbound share appropriate infrastructure without collapsing into one incorrect model.

---

# 165. Final Security Acceptance

Do not release until:

- [ ] Redirect destinations are validated before connection.
- [ ] DNS rebinding protection exists under strict SSRF policy.
- [ ] Arbitrary CURLOPT cannot bypass safety.
- [ ] Proxy/security behavior is defined.
- [ ] URL userinfo is rejected.
- [ ] TLS defaults are secure.
- [ ] SMTP authentication cannot silently downgrade.
- [ ] IMAP/POP authentication cannot silently downgrade.
- [ ] SMTP localDomain cannot inject commands.
- [ ] Mailbox credentials cannot inject commands.
- [ ] SMTP responses are bounded.
- [ ] IMAP literals/responses are bounded.
- [ ] POP3 responses are bounded.
- [ ] MIME parser work is bounded during parsing.
- [ ] Webhook replay is atomic.
- [ ] Webhook signatures support rotation safely.
- [ ] Inbound gRPC exceptions do not expose internal details.
- [ ] Events/logs redact secrets.
- [ ] Listener failures cannot bypass cleanup.

---

# 166. Final Correctness Acceptance

- [ ] Request overrides beat defaults.
- [ ] Explicit headers beat default headers.
- [ ] Signing always occurs after final request mutation.
- [ ] Stable idempotency survives retry.
- [ ] Unsafe requests do not retry accidentally.
- [ ] Upload sources are actually repeatable before retry.
- [ ] Multipart files cannot leak.
- [ ] Duplicate HTTP headers behave case-insensitively.
- [ ] Cookie precedence is correct.
- [ ] DKIM signs the actual wire email.
- [ ] Email Date is stable.
- [ ] Email Message-ID is stable.
- [ ] MIME boundaries are stable.
- [ ] Reported Message-ID equals wire ID.
- [ ] Non-seekable attachments cannot silently disappear.
- [ ] Email retry/fallback cannot consume exhausted sources.
- [ ] Raw inbound email is preserved exactly.
- [ ] External DKIM fixtures pass.
- [ ] RSA DKIM works.
- [ ] Ed25519 DKIM works when supported.
- [ ] Multiple DKIM signatures work.
- [ ] Webhook retry timestamps remain valid.
- [ ] Webhook retry count reflects real network attempts.
- [ ] gRPC retry requires transient failure and retry-safe operation.
- [ ] gRPC overall deadline does not reset per attempt.
- [ ] Streaming semantics are explicit and truthful.

---

# 167. Final Performance Acceptance

- [ ] No unnecessary complete MIME pre-render before SMTP send.
- [ ] Large email attachments stream incrementally.
- [ ] Large HTTP uploads stream incrementally.
- [ ] Multipart does not duplicate entire streams.
- [ ] Parser limits prevent excessive allocation early.
- [ ] Rate limiter hot path is bounded.
- [ ] Circuit breaker hot path remains cheap.
- [ ] Event-disabled paths remain cheap.
- [ ] HTTP request preparation avoids repeated serialization.
- [ ] Email preparation avoids repeated MIME generation.
- [ ] gRPC streaming does not accumulate unbounded arrays.
- [ ] 1/10/25 MB email benchmarks are recorded.
- [ ] HTTP large transfer benchmarks are recorded.
- [ ] Benchmark regressions are reviewed before release.

---

# 168. Final Release Gate

Before tagging:

- [ ] `composer validate --strict`.
- [ ] `infocyph/phpforge` remains `dev-main@dev`.
- [ ] PHPForge full CI passes.
- [ ] PHP lint passes.
- [ ] Static analysis passes.
- [ ] Style checks pass.
- [ ] Unit tests pass.
- [ ] Integration tests pass.
- [ ] Security/adversarial tests pass.
- [ ] Optional-extension matrix passes.
- [ ] Documentation builds with warnings as errors.
- [ ] Documentation examples match real APIs.
- [ ] Benchmarks are recorded.
- [ ] Large-payload benchmarks are accepted.
- [ ] Clean production consumer installation passes.
- [ ] Composer package/archive contents are inspected.
- [ ] Public repository Composer metadata matches release source.
- [ ] Runtime dependencies remain intentionally minimal.

---

# 169. Explicit Non-Goals for This Major

Do not broaden scope unnecessarily into:

- HTTP application framework;
- controllers/router;
- full SMTP server;
- custom gRPC wire implementation;
- custom protobuf compiler;
- Kafka;
- AMQP;
- generic queue system;
- SMS;
- push notifications;
- Laravel-specific package;
- Symfony-specific package;
- service container;
- automatic reflection/DI system;
- generic distributed cache;
- generic distributed circuit breaker;
- template framework.

These may be separate future projects or integrations.

---

# 170. Final Product Definition

After this major, TalkingBytes should be accurately described as:

> **A high-performance, protocol-aware PHP communication toolkit providing complete email communication, secure HTTP client communication, authenticated webhook delivery/reception, and efficient bidirectional gRPC service communication.**

The intended architectural roles are:

```text
Email
→ complete inbound + outbound mail lifecycle

HTTP
→ secure, reliable external/general HTTP communication

Webhook
→ signed asynchronous HTTP callbacks in both directions

gRPC
→ typed internal service-to-service request/response and streaming
```

The guiding design rule is:

> **Share infrastructure where semantics genuinely match; preserve protocol-specific models where they do not.**

The guiding implementation rule is:

> **Do not remove capability merely for architectural cleanliness. Redesign unsafe or inefficient implementations while retaining the useful capability.**

The guiding performance rule is:

> **Eliminate repeated work, copies, scans and unnecessary abstractions before pursuing micro-optimizations.**

The guiding release rule is:

> **A feature is not complete merely because its happy path works; its retry, malformed-input, large-payload, concurrency, resource-cleanup and security behavior must also be correct.**

Once every applicable item in this specification is implemented, tested, benchmarked and documented, freeze the architecture and release the next major version.