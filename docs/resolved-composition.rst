Resolved Protocol Composition
=============================

Purpose
-------

Hosts such as Foundation often own named profiles, application paths, secret
resolution, production policy, and DI lifetime. TalkingBytes should own the
protocol mechanics after those values are resolved.

The resolved composition APIs keep that boundary explicit:

- ``HttpClient::fromResolvedConfig()`` applies HTTP auth, cookies, retry,
  rate limiting, circuit breaking, and idempotency. Hosts that already
  validated the base HTTP configuration can pass ``baseConfig:`` with an
  ``HttpClientConfig`` to avoid parsing those base options again.
- ``EmailSenderFactory::fromResolvedConfig()`` creates the selected transport
  and composes fallbacks, retry, rate limiting, and DKIM.
- ``EmailLimits::fromArray()`` parses parser limits natively.
- ``GrpcClientFactory`` centralizes callable/native/generated client creation
  and retry-profile application.
- ``GrpcClient::usingGeneratedStub()`` is the direct generated-stub convenience
  path.
- ``Webhook::senderFromResolvedConfig()``,
  ``Webhook::verifierFromResolvedConfig()`` and
  ``Webhook::receiverFromResolvedConfig()`` apply resolved protocol policy.

Host responsibilities
---------------------

The host should resolve these before calling TalkingBytes:

- the selected profile name;
- relative application paths;
- secret source and production-secret policy;
- DI lifetime and sharing policy;
- application handler/service lookup;
- concrete replay-store implementation.

TalkingBytes does not become a named-profile repository or service container.

HTTP
----

.. code-block:: php

   $resolved = [
       'timeoutSeconds' => 10,
       'auth' => [
           'driver' => 'bearer',
           'token' => $resolvedToken,
       ],
       'retry' => [
           'enabled' => true,
           'attempts' => 3,
           'base_delay_ms' => 250,
           'max_retry_after_seconds' => 30,
       ],
   ];

   $client = HttpClient::fromResolvedConfig($resolved);

When a host has already parsed or tightened the base HTTP options, it can reuse
that typed configuration while TalkingBytes still owns protocol composition:

.. code-block:: php

   $base = HttpClientConfig::fromArray($resolved);

   // A host may validate or construct a stricter typed base configuration here.
   $client = HttpClient::fromResolvedConfig(
       $resolved,
       baseConfig: $base,
   );

``HttpClientFactory::fromConfig($base, $resolved)`` exposes the same native
composition path for hosts that use the factory directly. When a typed base
configuration is supplied, base keys in the resolved array are not reparsed;
the array contributes only the optional auth/cookie/resilience/idempotency
sections.

Resolved HTTP sections use these native keys:

- ``auth.driver``: ``none``, ``bearer``, ``basic``, header API key, or query API key
- ``cookies.enabled``
- ``retry.enabled`` plus ``attempts``, ``base_delay_ms``, ``max_retry_after_seconds``
- ``rate_limit.enabled`` plus ``max_requests`` and ``per_seconds``
- ``circuit_breaker.enabled`` plus ``failure_threshold`` and ``cool_down_seconds``
- ``idempotency.enabled`` plus optional ``header``

Email
-----

``EmailSenderFactory::fromResolvedConfig()`` expects a resolved primary
``transport`` array, optional resolved ``fallbacks`` arrays, and optional
``retry``, ``rate_limit`` and ``dkim`` sections. ``retry`` uses ``enabled``,
``max_attempts``, ``delay_ms`` and ``policy`` (``fixed`` or
``backoff``/``exponential``); ``rate_limit`` uses ``max_requests`` and
``per_seconds``. File paths in log/spool/DKIM configuration should already be
absolute or otherwise resolved by the host.

gRPC
----

``GrpcClientFactory`` accepts the resolved retry section while the host supplies
the callable/native/generated endpoint object. The retry section uses
``enabled``, ``attempts``, ``base_delay_ms``, optional ``max_delay_ms``, and
``jitter_ratio``. Generated stubs no longer need host code to construct
``GeneratedStubGrpcInvoker`` for ordinary use.

Webhook
-------

Outbound resolved configuration can include ``signing_secret``,
``max_payload_bytes``, and a ``retry`` section with ``enabled``, ``attempts``,
``base_delay_ms`` and ``max_retry_after_seconds``. Inbound resolved
configuration can include ``max_age_seconds`` and ``max_payload_bytes`` plus a
nested ``replay`` section containing ``enabled``, ``ttl_seconds`` and
``namespace``. The replay-store object itself remains host-provided; its TTL
must satisfy the elapsed-duration lower-bound contract.
