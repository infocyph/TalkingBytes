Architecture
============

Core design
-----------

TalkingBytes uses a shared communication core.

- ``CommunicationRequest`` is the platform request envelope.
- ``CommunicationResult`` is the shared result shape.
- protocol responses (``HttpResponse``, ``GrpcResponse``, email result objects) remain protocol-specific.
- middleware (retry, timeout, auth, logging, rate limit, circuit breaker) is transport-agnostic.

Event model
-----------

A shared event bus dispatches protocol events.

Examples:

- ``http.request.start`` / ``http.request.finish``
- ``grpc.request.start`` / ``grpc.request.finish``
- ``webhook.send.start`` / ``webhook.send.finish``
- ``email.send.start`` / ``email.send.finish``
- ``mailbox.command.start`` / ``mailbox.command.finish``

Sensitive values are redacted before dispatch.

Module boundaries
-----------------

- ``Core``: contracts, middleware pipeline, common result/error types.
- ``Http``: cURL and cURL-multi transport layer.
- ``Grpc``: adapter for callback/native gRPC invocation.
- ``Webhook``: send/verify/receive workflows on top of HTTP.
- ``Email``: outbound transports + inbound parser + mailbox operations.

Testing strategy
----------------

Every module includes fakes/assertion helpers and fake protocol servers where useful.

- HTTP: fake transport + concurrent pool tests.
- gRPC: fake caller + retry tests.
- Webhook: signature/replay/redaction tests.
- Email: SMTP/IMAP/POP3/parser/bounce/authentication tests.
