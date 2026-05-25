HTTP (cURL)
===========

Overview
--------

The HTTP module is a lightweight cURL-native client.

Key features:

- immutable ``HttpRequest``
- ``CurlTransport`` for single requests
- ``CurlMultiTransport`` for concurrency
- retry policy with ``Retry-After`` support
- streaming upload/download
- request redaction and security guards
- fake/assertable testing transport

Quick usage
-----------

.. code-block:: php

   $result = \Infocyph\TalkingBytes\Http\HttpClient::curl()
       ->withBearerToken($token)
       ->timeout(10)
       ->postJson('https://api.example.com/orders', [
           'order_id' => 1001,
           'amount' => 500,
       ]);

   if ($result->successful) {
       $data = $result->response->json();
   }

Requests
--------

Factory methods:

- ``HttpRequest::get()``
- ``HttpRequest::post()``
- ``HttpRequest::put()``
- ``HttpRequest::patch()``
- ``HttpRequest::delete()``
- ``HttpRequest::head()``
- ``HttpRequest::options()``

Request helpers include:

- headers (``header()``, ``headers()``, ``withoutHeader()``)
- query (``query()``, ``queries()``, ``withoutQuery()``)
- body (``json()``, ``form()``, ``raw()``, ``multipart()``)
- timeouts and redirect policy
- TLS and CA/certificate options
- response/body size limits

Response helpers
----------------

``HttpResponse`` exposes:

- status classifiers: ``ok()``, ``redirect()``, ``clientError()``, ``serverError()``, ``failed()``
- body parsers: ``json()``, ``jsonOrNull()``, ``text()``
- header accessors: ``header()``, ``headerLine()``
- transfer stats via ``stats()``

Retry policy
------------

``HttpRetryPolicy`` retries transient failures/statuses (for example ``408``, ``429``, ``5xx``).

.. code-block:: php

   $client = \Infocyph\TalkingBytes\Http\HttpClient::curl()
       ->withHttpRetry(\Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy::standard(attempts: 3));

Concurrency
-----------

.. code-block:: php

   $pool = \Infocyph\TalkingBytes\Http\HttpClient::multi(maxConcurrency: 10)
       ->sendMany([
           'users' => \Infocyph\TalkingBytes\Http\HttpRequest::get('https://api.example.com/users'),
           'orders' => \Infocyph\TalkingBytes\Http\HttpRequest::get('https://api.example.com/orders'),
       ]);

   $users = $pool->get('users');

Pool results preserve user keys and provide success/failure helpers.

Streaming
---------

Download modes:

- ``downloadTo()`` for basic download
- ``streamDownloadTo()`` for temp-file streaming + atomic finalize

Upload modes:

- multipart file/data/stream parts
- direct upload from file/stream (transport-driven)

Security
--------

Available controls:

- host allow list
- host block list
- private/reserved network blocking
- redirect destination validation
- strict header and URL validation

Redaction
---------

``HttpRedactor`` redacts sensitive headers/query values in event payloads.

Testing
-------

Use ``HttpClient::fake()`` with assertions:

- ``assertRequested()``
- ``assertRequestCount()``
- ``assertNothingRequested()``
- ``assertRequestedWhere()``
