Naming Map
==========

Use these boundaries consistently:

- ``Emailer``: outbound email pipeline
- ``EmailReceiver``: one-by-one inbound source (for example spool)
- ``Mailbox``: IMAP/foldered mailbox operations
- ``Pop3Mailbox``: POP3-specific mailbox operations
- ``RawEmailParser`` and parser stack: raw ``.eml`` parsing
- ``HttpClient``: cURL HTTP entry point
- ``GrpcClient``: outbound gRPC callback/native/generated client entry point
- ``GrpcInboundDispatcher``: host-driven inbound gRPC request/response dispatch
- ``Webhook``: webhook send/verify/receive factory entry point
- ``WebhookSender`` / ``WebhookReceiver``: configured outbound/inbound webhook pipelines
