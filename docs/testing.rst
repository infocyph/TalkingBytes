Testing
=======

Overview
--------

Each protocol module ships with fake/assertable tools to enable deterministic tests.

HTTP testing
------------

- ``FakeHttpTransport``
- ``AssertableHttpTransport``
- ``SequenceHttpTransport``
- ``SpyHttpTransport``

gRPC testing
------------

- ``FakeGrpcCaller``
- ``AssertableGrpcCaller``

Webhook testing
---------------

- ``FakeWebhookSender``
- ``AssertableWebhookSender``
- ``WebhookTestFactory`` for signed payload/header generation

Email testing
-------------

- ``FakeEmailTransport``
- ``AssertableEmailTransport``
- protocol fake-server tests for SMTP/IMAP/POP3 paths

Guideline
---------

Prefer fake transports in module tests and integration boundaries, and reserve live network tests for environment-specific pipelines.
