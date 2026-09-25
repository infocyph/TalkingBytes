Retry and Transport
===================

Transport stack
---------------

Single-request transport uses:

- ``CurlHandleConfigurator`` for ``curl_setopt`` mapping
- ``CurlTransport`` for execution
- ``CurlResultFactory`` for ``CommunicationResult`` + ``HttpResponse`` creation

Retry policy
------------

Use ``HttpRetryPolicy`` with ``HttpClient::withHttpRetry()``.

.. code-block:: php

   use Infocyph\TalkingBytes\Http\HttpClient;
   use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;

   $client = HttpClient::curl()->withHttpRetry(
       HttpRetryPolicy::standard(attempts: 3, maxRetryAfterSeconds: 30)
   );

Default transient statuses include:

- 408
- 425
- 429
- 500
- 502
- 503
- 504

``Retry-After`` header values are supported in both second and HTTP-date forms.

Retry eligibility
-----------------

Automatic retry is enabled by method safety as well as policy. ``GET``,
``HEAD``, ``OPTIONS``, ``PUT`` and ``DELETE`` are retry-eligible by default.
``POST`` and ``PATCH`` require either an ``Idempotency-Key`` header (for
example through ``HttpClient::withIdempotency()``) or an explicit request-level
``allowUnsafeMethodRetry()`` opt-in. Automatic retry also requires a repeatable
upload source; non-repeatable streams fail before a retry loop is attempted.

Client configuration object
---------------------------

Use ``HttpClientConfig::fromArray()`` for centralized defaults:

- timeout/connect-timeout
- redirect flags
- TLS verify flags
- proxy settings
- user-agent
- max response bytes
- default headers
