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

Replay claims are retained beyond the complete remaining signature acceptance
window, even when a shorter custom replay TTL is configured. The receiver
derives the effective TTL from the verified signature timestamp, verifier
wall-clock instant, and configured max age, then reserves one additional
max-age interval as a backward wall-clock correction budget. This includes
valid future timestamps near the acceptance boundary.

Process-local retention uses monotonic elapsed time. Distributed replay stores
must honor the requested TTL as an elapsed-duration lower bound and must not
expire a claim early because their wall clock moves. Deployments that permit
backward corrections larger than the verifier max-age interval must configure
a replay TTL large enough to cover that additional clock-discipline budget.
Replay-store failures remain fail closed.
