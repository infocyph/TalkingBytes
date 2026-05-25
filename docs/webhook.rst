Webhook
=======

Overview
--------

Webhook builds on the HTTP module and adds:

- signed outbound webhook delivery
- verification and structured receive pipeline
- replay protection hook
- fake/assertable sender for tests

Sending
-------

.. code-block:: php

   $delivery = \Infocyph\TalkingBytes\Webhook\Webhook::sender($httpClient)
       ->withSecret('whsec_test')
       ->send(
           \Infocyph\TalkingBytes\Webhook\WebhookMessage::event('order.created')
               ->url('https://merchant.example.com/webhook')
               ->payload(['order_id' => 1001])
       );

Standard headers
----------------

Webhook sender sets reserved headers such as:

- ``X-TB-Event``
- ``X-TB-Delivery``
- ``X-TB-Timestamp``
- ``X-TB-Signature``
- ``X-TB-Attempt``

Signature format is ``t=<timestamp>,v1=<hmac>``.

Verification
------------

.. code-block:: php

   $verification = \Infocyph\TalkingBytes\Webhook\Webhook::verifier('whsec_test')
       ->verify(
           payload: $rawBody,
           signatureHeader: $headers['X-TB-Signature'] ?? '',
           timestampHeader: $headers['X-TB-Timestamp'] ?? '',
       );

Verifier checks:

- signature presence and format
- timestamp validity and tolerance window
- HMAC match using ``hash_equals``

Receiving
---------

.. code-block:: php

   $event = \Infocyph\TalkingBytes\Webhook\Webhook::receiver('whsec_test')
       ->withReplayStore(new \Infocyph\TalkingBytes\Webhook\InMemoryWebhookReplayStore(), ttlSeconds: 86400)
       ->receive($rawBody, $headers);

``WebhookEvent`` includes event name, delivery id, payload, and timestamp.

Testing
-------

.. code-block:: php

   $fake = \Infocyph\TalkingBytes\Webhook\Webhook::fake();

   $fake->send(
       \Infocyph\TalkingBytes\Webhook\WebhookMessage::event('order.created')
           ->url('https://example.com/webhook')
           ->payload(['id' => 1])
   );

   $fake->assertSent('order.created');

Events and redaction
--------------------

Webhook emits:

- ``webhook.send.start``
- ``webhook.retry``
- ``webhook.send.finish``
- ``webhook.send.failed``
- ``webhook.verified``
- ``webhook.rejected``
- ``webhook.received``

Raw payload, signature, and secret values are redacted from event payloads.
