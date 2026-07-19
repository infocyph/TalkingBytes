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

``InMemoryWebhookReplayStore`` exists for tests and local development. It
retains at most 10,000 live delivery IDs by default and fails closed when that
capacity is exhausted. Use the ``maxEntries`` constructor argument to select a
smaller bound for constrained processes.

Production guidance
-------------------

Use Redis/database-backed store with TTL. The module intentionally avoids
hard-coding persistence dependencies.
