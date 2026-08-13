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
