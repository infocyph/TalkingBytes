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

Email factories accept the same injected dispatcher:

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Email;

   $sender = Email::sender($events)->usingNull();
   $receiver = Email::receiver($events);
   $mailbox = Email::mailbox($events);

Compatibility adapter
---------------------

.. code-block:: php

   \Infocyph\TalkingBytes\Core\Event\CommunicationEventBus::listen($listener);

The static bus is retained only as an explicit compatibility facade. Normal
protocol graphs do not consult it. Prefer constructor/factory injection in all
new code, especially long-running workers and Fiber-based runtimes.

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
