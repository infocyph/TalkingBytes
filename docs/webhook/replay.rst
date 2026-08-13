Replay Protection
=================

Interface
---------

Use ``WebhookReplayStore`` to prevent duplicate processing.

.. code-block:: php

   interface WebhookReplayStore
   {
       public function claim(
           string $namespace,
           string $deliveryId,
           int $ttlSeconds,
       ): bool;
   }

``claim()`` must be atomic and returns true only for the first claimant. The
namespace isolates tenants/endpoints that may legitimately reuse a delivery ID.

Built-in implementation
-----------------------

``InMemoryWebhookReplayStore`` exists for tests and local development. It
retains at most 10,000 live delivery IDs by default and fails closed when that
capacity is exhausted. Use the ``maxEntries`` constructor argument to select a
smaller bound for constrained processes.

Production guidance
-------------------

Use a Redis/database-backed atomic insert-if-absent operation with TTL. A
separate check-then-write implementation is race-prone and is not sufficient.
