Replay Protection
=================

Interface
---------

Use ``WebhookReplayStore`` to prevent duplicate processing.

.. code-block:: php

   interface WebhookReplayStore
   {
       public function seen(string $deliveryId): bool;
       public function remember(string $deliveryId, int $ttlSeconds): void;
   }

Built-in implementation
-----------------------

``InMemoryWebhookReplayStore`` exists for tests and local development.

Production guidance
-------------------

Use Redis/database-backed store with TTL. The module intentionally avoids
hard-coding persistence dependencies.
