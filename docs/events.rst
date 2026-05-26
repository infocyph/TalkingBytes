Events
======

Overview
--------

TalkingBytes dispatches lifecycle events through a shared communication event bus.

Set dispatcher
--------------

.. code-block:: php

   \Infocyph\TalkingBytes\Core\Event\CommunicationEventBus::listen(
       static function (string $event, array $payload): void {
           // route to logger/metrics
       }
   );

Reset dispatcher
----------------

.. code-block:: php

   \Infocyph\TalkingBytes\Core\Event\CommunicationEventBus::reset();

Event families
--------------

- HTTP: ``http.request.*``, ``http.retry``, ``http.pool.*``
- gRPC: ``grpc.request.*``, ``grpc.retry``
- Webhook: ``webhook.send.*``, ``webhook.retry``, ``webhook.verified``, ``webhook.rejected``, ``webhook.received``
- Email: ``email.send.*``, ``email.receive.*``, ``email.parse.failed``
- Mailbox: ``mailbox.command.*``

Security note
-------------

Sensitive values are redacted before events are emitted. Do not rely on events for raw secret access.
