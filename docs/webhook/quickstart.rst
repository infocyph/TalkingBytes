Webhook Quick Start
===================

Send
----

.. code-block:: php

   use Infocyph\TalkingBytes\Webhook\Webhook;
   use Infocyph\TalkingBytes\Webhook\WebhookMessage;

   $delivery = Webhook::sender($httpClient)
       ->withSecret('whsec_test')
       ->send(
           WebhookMessage::event('order.created')
               ->url('https://merchant.example.com/webhook')
               ->payload(['order_id' => 1001])
       );

Verify + receive
----------------

.. code-block:: php

   $event = Webhook::receiver('whsec_test')->receive($rawBody, $headers);

Header model
------------

Standard headers include:

- ``X-TB-Event``
- ``X-TB-Delivery``
- ``X-TB-Timestamp``
- ``X-TB-Signature``
- ``X-TB-Attempt``
