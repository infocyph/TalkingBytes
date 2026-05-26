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
