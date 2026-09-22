Replay Protection
=================

Interface
---------

Use ``WebhookReplayStore`` to prevent duplicate processing. The native receiver
requires a ``v2`` signature authenticating both event and delivery ID before
claiming replay state. Changing either header invalidates the signature.
Backend atomicity alone cannot secure an unsigned delivery ID.

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

Use a Redis/database-backed atomic insert-if-absent operation with TTL. The
atomic claim must provide one-winner semantics across competing processes; a
separate check-then-write implementation is race-prone and is not sufficient.

Replay backend errors are fail-closed. Implementations must throw when the
atomic claim cannot be completed instead of treating an unavailable backend as
an unused delivery ID.

The built-in in-memory store is single-process only. It is appropriate for
tests and local development, not for multi-worker or multi-node replay
protection.
