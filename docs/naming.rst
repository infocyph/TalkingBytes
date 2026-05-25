Naming Map
==========

Use these boundaries consistently:

- ``Emailer``: outbound email pipeline
- ``EmailReceiver``: one-by-one inbound source (for example spool)
- ``Mailbox``: IMAP/foldered mailbox operations
- ``Pop3Mailbox``: POP3-specific mailbox operations
- ``RawEmailParser`` and parser stack: raw ``.eml`` parsing
- ``HttpClient``: cURL HTTP entry point
- ``GrpcClient``: gRPC adapter entry point
- ``Webhook``: webhook send/verify/receive entry point
