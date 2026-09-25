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

Retry-safety gate
-----------------

Installing retry middleware does not make every RPC retryable. A request is
retried only when it explicitly opts in with ``withRetrySafety()``:

.. code-block:: php

   $request = (new GrpcRequest(
       '/orders.v1.OrderService/Get',
       ['order_id' => 1001],
   ))->withRetrySafety();

   $result = $client->send($request);

Use that opt-in only for idempotent operations, or where the application has a
reliable deduplication contract for non-idempotent RPCs. The retry loop keeps
all attempts inside the original monotonic deadline budget.
