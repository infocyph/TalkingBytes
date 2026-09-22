Concurrency
===========

Entry point
-----------

Use ``HttpClient::multi($maxConcurrency)`` and send keyed request arrays.

.. code-block:: php

   use Infocyph\TalkingBytes\Http\HttpClient;
   use Infocyph\TalkingBytes\Http\HttpRequest;

   $pool = HttpClient::multi(10)->sendMany([
       'users' => HttpRequest::get('https://api.example.com/users'),
       'orders' => HttpRequest::get('https://api.example.com/orders'),
   ]);

Pool result helpers
-------------------

``PoolResult`` provides:

- ``all()``
- ``get($key)``
- ``successful()`` and ``failed()``
- ``successfulCount()`` and ``failedCount()``
- ``firstError()``
- ``hasFailures()``

Behavior
--------

- user-defined keys are preserved
- max concurrency is enforced with a rolling window rather than fixed chunks
- when one active handle completes, the next pending request is admitted immediately
- per-request configuration is respected
- ``stopSchedulingOnFailure()`` stops only new admissions after a failure is observed; already-active requests are allowed to finish
- active requests are not described as fail-fast unless explicit cancellation is supplied
- cleanup runs for active handles on completion, cancellation, scheduler error, and early stop paths
- pool duration uses the monotonic clock

Cancellation
------------

Pass a ``CancellationSignal`` to ``HttpClient::multi(..., cancellation: $signal)``
or use ``RequestPool::withCancellation()``.

When cancellation is observed, the pool stops admitting requests, removes active
cURL handles, aborts partial streamed-download temp files, and returns
deterministic cancelled results for active and not-yet-started requests.
