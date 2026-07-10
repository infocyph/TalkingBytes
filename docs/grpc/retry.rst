gRPC Retry
==========

Policy
------

Use ``GrpcRetryPolicy`` with ``withGrpcRetry()``.

.. code-block:: php

   use Infocyph\TalkingBytes\Grpc\GrpcClient;
   use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
   use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
   use Infocyph\TalkingBytes\Grpc\GrpcStatus;
   use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;

   $client = GrpcClient::using(
       static fn (GrpcRequest $request): GrpcResponse => new GrpcResponse(GrpcStatus::Ok, $request->message),
   )->withGrpcRetry(
       GrpcRetryPolicy::standard(attempts: 3, baseDelayMs: 100)
   );

Policy supports backoff control, jitter, and retryable status rules.

Idempotency guidance
--------------------

Retry only idempotent operations unless your application has deduplication
semantics for non-idempotent RPC methods.
