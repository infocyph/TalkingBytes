HTTP Testing
============

Fake transports
---------------

Available testing transports:

- ``FakeHttpTransport``
- ``AssertableHttpTransport``
- ``SequenceHttpTransport``
- ``SpyHttpTransport``

Typical flow
------------

.. code-block:: php

   $client = \Infocyph\TalkingBytes\Http\HttpClient::fake();

   $client->send(\Infocyph\TalkingBytes\Http\HttpRequest::get('https://api.example.com/users'));

   $client->assert()->assertRequestCount(1);

Assertions
----------

- request count and URL/method checks
- predicate checks with ``assertRequestedWhere``
- header/body inspections for JSON/form/multipart cases

Pool tests
----------

Concurrent tests validate:

- key-preserving result mapping
- fail-fast behavior
- mixed success/failure collection
- pool event lifecycle dispatch
