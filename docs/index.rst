TalkingBytes Documentation
=========================

TalkingBytes is a transport-agnostic communication toolkit for PHP.

It provides:

- outbound and inbound email (SMTP, sendmail, spool, IMAP, POP3, parser)
- cURL-native HTTP client with retry, streaming, fakes, and concurrency
- webhook sender/receiver with HMAC verification and replay protection
- gRPC adapter with retry, fake callers, and native invoker bridge
- shared middleware pipeline, event bus, testing transports, and resilience primitives

.. toctree::
   :maxdepth: 2
   :caption: Start Here

   getting-started
   architecture

.. toctree::
   :maxdepth: 2
   :caption: Modules

   http
   grpc
   webhook
   email

.. toctree::
   :maxdepth: 2
   :caption: Cross-Cutting

   events
   testing
   security
   performance
   extensions
   naming
   release-checklist
