Middleware and Resilience
=========================

Overview
--------

TalkingBytes provides a framework-agnostic middleware pipeline shared by the
HTTP and gRPC clients. Middleware is immutable client configuration: the
pipeline is compiled when the client is created or changed and reused for
subsequent sends.

HTTP helpers
------------

``HttpClient`` provides direct helpers for the common policies:

- authentication: ``withBasicAuth()``, ``withBearerToken()``,
  ``withApiKeyHeader()``, ``withApiKeyQuery()``, and ``withSigner()``
- retry: ``withHttpRetry()`` or ``withRetry()``
- limits: ``withTimeout()`` and ``withRateLimit()``
- resilience: ``withCircuitBreaker()``
- request behavior: ``withDefaultHeaders()`` and ``withIdempotency()``
- observability: ``withLogging()``

.. code-block:: php

   use Infocyph\TalkingBytes\Http\HttpClient;
   use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;
   use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
   use Infocyph\TalkingBytes\Resilience\RateLimiter;

   $client = HttpClient::curl()
       ->withBearerToken($token)
       ->withHttpRetry(HttpRetryPolicy::standard(attempts: 3))
       ->withTimeout(10)
       ->withRateLimit(new RateLimiter(maxRequests: 100, perSeconds: 60))
       ->withCircuitBreaker(new CircuitBreaker(
           failureThreshold: 5,
           coolDownSeconds: 30,
       ));

Custom middleware
-----------------

Implement ``MiddlewareInterface`` when a policy must wrap transport execution.
Middleware receives a ``CommunicationRequest`` and a closure for the next
stage.

.. code-block:: php

   use Closure;
   use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
   use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
   use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

   final readonly class TenantHeaderMiddleware implements MiddlewareInterface
   {
       public function __construct(private string $tenantId)
       {
       }

       public function handle(
           CommunicationRequest $request,
           Closure $next,
       ): CommunicationResult {
           $headers = $request->headers;
           $headers['X-Tenant-Id'] = $this->tenantId;

           return $next($request->withHeaders($headers));
       }
   }

   $client = HttpClient::curl()
       ->withMiddleware(new TenantHeaderMiddleware('tenant-42'));

gRPC middleware
---------------

``GrpcClient`` accepts shared middleware through ``withMiddleware()`` and
``withMiddlewares()``. Use ``withGrpcRetry()`` for the protocol-aware retry
defaults.

Operational guidance
--------------------

- Add middleware only when the policy is needed; unused policies impose no
  request-path work.
- Reuse configured immutable clients instead of rebuilding them for every
  request.
- Keep retry attempts, rate limits, timeouts, and circuit-breaker thresholds
  bounded.
- Retry only idempotent operations unless the application supplies
  deduplication semantics.
- ``RateLimiter`` and ``CircuitBreaker`` are process-local. Use a shared
  application-level implementation when policy state must span workers.
