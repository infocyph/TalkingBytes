Events
======

Overview
--------

TalkingBytes dispatches lifecycle events through an injected ``EventDispatcher``.
Entrypoints wrap dispatchers with ``BestEffortEventDispatcher`` so monitoring
failures never change protocol outcomes.

Inject a dispatcher
-------------------

.. code-block:: php

   use Infocyph\TalkingBytes\Core\Event\CallableEventDispatcher;
   use Infocyph\TalkingBytes\Http\HttpClient;

   $events = new CallableEventDispatcher(
       static function (string $event, array $payload): void {
           // route to logger/metrics
       },
   );

   $client = HttpClient::curl($events);

Compatibility adapter
---------------------

.. code-block:: php

   \Infocyph\TalkingBytes\Core\Event\CommunicationEventBus::listen($listener);

The static bus is retained for compatibility. Prefer constructor/factory
injection in long-running workers and tests to avoid global state leakage.

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
