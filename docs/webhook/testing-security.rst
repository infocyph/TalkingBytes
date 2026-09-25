Webhook Testing and Security
============================

Testing utilities
-----------------

- ``FakeWebhookSender``
- ``AssertableWebhookSender``
- ``WebhookTestFactory`` for signed payload/header fixtures

Assertions include event, URL, payload, header, signature, and count checks.

Event lifecycle
---------------

Webhook events:

- ``webhook.send.start``
- ``webhook.retry``
- ``webhook.send.finish``
- ``webhook.send.failed``
- ``webhook.verified``
- ``webhook.rejected``
- ``webhook.received``

Redaction guarantees
--------------------

Event payloads redact or omit:

- raw webhook body
- raw signature values
- shared secret values
- sensitive URL query parameters

Replay retention policy
-----------------------

Replay claims are retained for at least the complete remaining signature
acceptance window, even when a shorter custom replay TTL is configured. The
receiver derives the effective TTL from the verified signature timestamp,
verifier wall-clock instant, and configured max age. This includes valid
future timestamps near the acceptance boundary. Distributed replay stores
must interpret claim TTL as wall-clock retention and fail closed when a claim
cannot be established.
