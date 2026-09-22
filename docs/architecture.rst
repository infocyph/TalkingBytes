Architecture
============

Core design
-----------

TalkingBytes keeps protocol semantics in protocol-owned APIs.

- ``HttpRequest`` and ``GrpcRequest`` are distinct request types.
- ``HttpMiddleware`` and ``GrpcMiddleware`` expose typed pipelines.
- ``CommunicationResult`` is the small shared result shape.
- protocol responses (``HttpResponse``, ``GrpcResponse``, email result objects) remain protocol-specific.
- retry decisions use shared timing primitives, while retry eligibility remains protocol-specific.

Event model
-----------

Protocol entrypoints accept an ``EventDispatcher``. Dispatch is best effort:
listener failures cannot change a delivery outcome. The static
``CommunicationEventBus`` remains a compatibility adapter, not the primary
dependency path.

Examples:

- ``http.request.start`` / ``http.request.finish``
- ``grpc.request.start`` / ``grpc.request.finish``
- ``webhook.send.start`` / ``webhook.send.finish``
- ``email.send.start`` / ``email.send.finish``
- ``mailbox.command.start`` / ``mailbox.command.finish``

Sensitive values are redacted before dispatch.

Runtime ownership and lifetime
------------------------------

TalkingBytes objects are safe to reuse only according to the state they own.

- immutable request/configuration objects may be reused.
- an ``HttpClient`` without mutable collaborators is an immutable reusable
  graph.
- ``CookieJar`` owns mutable session state and should be scoped to the
  intended request/session lifetime.
- ``CircuitBreaker`` and ``RateLimiter`` own resilience state; sharing
  them is an explicit host policy decision.
- mailbox/socket transports own connection/session state and should remain
  execution- or worker-owned rather than globally shared.
- generated/native gRPC invokers inherit the lifetime of their channel/stub and
  should be scoped deliberately by the host.
- fakes and spies own mutable test history and should be test-scoped.

No global registry is introduced for cookies, resilience state, mailbox
connections, native clients, cancellation, or protocol events.

Cancellation and host control
-----------------------------

Long-running protocol work accepts the small ``CancellationSignal`` boundary
where interruption is useful. TalkingBytes checks that signal around retries,
bounded waits, stream progress, concurrent HTTP scheduling, mailbox watches,
sendmail process supervision, and accepted inbound gRPC exchanges.

The host remains responsible for translating its own stop token, heartbeat,
release generation, or worker lifecycle into that signal. TalkingBytes does not
own worker supervision or process-global signal handling.

Optional capability coldness
----------------------------

Optional protocol capabilities remain cold until selected. HTTP, webhook, and
basic email graphs must not initialize gRPC, IMAP, POSIX, PCNTL, or Sodium
capabilities. RSA DKIM is OpenSSL-backed; Sodium is required only by Ed25519
DKIM. POSIX sendmail hardening is opportunistic and PCNTL is not part of the
normal runtime graph.

The security workflow contains a minimal-extension coldness gate that exercises
these boundaries with unloadable gRPC, IMAP, and POSIX extensions disabled. It
also enforces source-level confinement for compiled-in PCNTL and Sodium
capabilities.

Module boundaries
-----------------

- ``Core``: event, result, clock, sleeper, and retry-execution primitives.
- ``Http``: typed pipeline, cURL/cURL-multi transports, signing, redirects, and SSRF controls.
- ``Grpc``: typed pipeline plus callback/native gRPC invocation.
- ``Webhook``: send/verify/receive workflows on top of HTTP.
- ``Email``: outbound transports + inbound parser + mailbox operations.

Testing strategy
----------------

Every module includes fakes/assertion helpers and fake protocol servers where useful.

- HTTP: fake transport + concurrent pool tests.
- gRPC: fake caller + retry tests.
- Webhook: signature/replay/redaction tests.
- Email: SMTP/IMAP/POP3/parser/bounce/authentication tests.
- runtime: sequential/Fiber isolation, cancellation, and optional-capability
  coldness gates.
