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
- max concurrency is enforced
- per-request configuration is respected
- fail-fast mode is supported via request-pool options
- cleanup runs for active handles on early stop/error paths
