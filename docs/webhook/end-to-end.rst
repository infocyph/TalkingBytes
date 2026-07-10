Webhook End-to-End
==================

Outbound sender
---------------

.. code-block:: php

   use Infocyph\TalkingBytes\Http\HttpClient;
   use Infocyph\TalkingBytes\Webhook\Webhook;
   use Infocyph\TalkingBytes\Webhook\WebhookMessage;

   $delivery = Webhook::sender(HttpClient::curl())
       ->withSecret('whsec_prod')
       ->withRetryProfile(attempts: 3, baseDelayMs: 250)
       ->send(
           WebhookMessage::event('order.created')
               ->url('https://merchant.example.com/webhooks/orders')
               ->payload([
                   'order_id' => 'ord_1001',
                   'amount' => 5000,
               ])
               ->metadata(['tenant' => 'merchant-1'])
       );

   if (!$delivery->result->successful) {
       // inspect $delivery->delivery->statusCode and $delivery->delivery->error
   }

Inbound verifier + receiver
---------------------------

.. code-block:: php

   use Infocyph\TalkingBytes\Webhook\Webhook;

   $event = Webhook::receiver('whsec_prod')->receive(
       $rawBody,
       getallheaders(),
   );

   // $event->event, $event->deliveryId, $event->payload, $event->timestamp

Replay protection
-----------------

.. code-block:: php

   use Infocyph\TalkingBytes\Webhook\Replay\InMemoryWebhookReplayStore;
   use Infocyph\TalkingBytes\Webhook\Webhook;

   $receiver = Webhook::receiver('whsec_prod')
       ->withReplayStore(new InMemoryWebhookReplayStore(), ttlSeconds: 86400);

   $event = $receiver->receive($rawBody, getallheaders());

Security behavior
-----------------

- signature format: ``t=<timestamp>,v1=<hmac>``
- timestamp window enforced (default 300s)
- secret/signature/raw body are not emitted in event payloads
- reserved headers are sender-controlled:
  - ``X-TB-Event``
  - ``X-TB-Delivery``
  - ``X-TB-Timestamp``
  - ``X-TB-Signature``
  - ``X-TB-Attempt``

Failure handling
----------------

Receiver throws explicit exceptions for:

- missing/invalid signature headers
- stale timestamp
- invalid JSON payload
- missing event/delivery header
- replay-detected delivery id

Sender retries transient failures using ``WebhookRetryProfile`` and dispatches:

- ``webhook.send.start``
- ``webhook.retry``
- ``webhook.send.finish``
- ``webhook.send.failed``
