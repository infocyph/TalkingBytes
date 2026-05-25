gRPC Retry
==========

Policy
------

Use ``GrpcRetryPolicy`` with ``withGrpcRetry()``.

.. code-block:: php

   use Infocyph\TalkingBytes\Grpc\GrpcClient;
   use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;

   $client = GrpcClient::transport($transport)->withGrpcRetry(
       GrpcRetryPolicy::standard(attempts: 3, baseDelayMs: 100)
   );

Policy supports backoff control, jitter, and retryable status rules.

Idempotency guidance
--------------------

Retry only idempotent operations unless your application has deduplication
semantics for non-idempotent RPC methods.
