gRPC Inbound and Outbound
=========================

Scope
-----

TalkingBytes now supports both directions:

- outbound client calls via ``GrpcClient``
- inbound request dispatch via ``GrpcInboundDispatcher``
- host-controlled one-exchange execution via ``GrpcInboundSource`` and ``GrpcInboundExchange``

Module layout
-------------

- ``src/Grpc/GrpcClient.php`` outbound entrypoint
- ``src/Grpc/Sender/*`` outbound request/response/transport models
- ``src/Grpc/GrpcInboundDispatcher.php`` inbound entrypoint
- ``src/Grpc/Receiver/*`` inbound request/response/handler/source/exchange models

Outbound (Node A -> Node B)
---------------------------

.. code-block:: php

   use Infocyph\TalkingBytes\Grpc\GrpcClient;
   use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
   use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;

   $result = GrpcClient::using($caller)->send(new GrpcRequest(
       '/orders.v1.OrderService/Create',
       ['order_id' => 1001],
       (new GrpcMetadata())->withValue('x-request-id', 'req-123'),
       deadlineSeconds: 3.0,
   ));

Node A + Node B full flow (framework-agnostic)
----------------------------------------------

.. code-block:: php

   // Node A (sender)
   use Infocyph\TalkingBytes\Grpc\GrpcClient;
   use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
   use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;

   $sender = GrpcClient::usingNative($generatedInvoker);

   $outboundResult = $sender->send(new GrpcRequest(
       '/orders.v1.OrderService/Create',
       ['order_id' => 1001, 'amount' => 500],
       (new GrpcMetadata())->withValue('x-request-id', 'req-1001'),
       deadlineSeconds: 2.5,
   ));

   if (!$outboundResult->successful) {
       throw new RuntimeException($outboundResult->error ?? 'Outbound call failed');
   }

   // Node B (receiver/handler side)
   use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
   use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
   use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;

   $receiver = GrpcInboundDispatcher::new()->withHandler(
       '/orders.v1.OrderService/Create',
       static function (GrpcInboundRequest $request): GrpcInboundResponse {
           return GrpcInboundResponse::ok([
               'accepted' => true,
               'order_id' => $request->message['order_id'] ?? null,
           ]);
       },
   );

   $inboundResponse = $receiver->receive(
       method: '/orders.v1.OrderService/Create',
       message: ['order_id' => 1001, 'amount' => 500],
       headers: (new GrpcMetadata())->withValue('x-request-id', 'req-1001'),
       deadlineSeconds: 2.5,
   );

Inbound (Node B request handling)
---------------------------------

.. code-block:: php

   use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
   use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
   use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;

   $server = GrpcInboundDispatcher::new()->withHandler(
       '/orders.v1.OrderService/Create',
       static function (GrpcInboundRequest $request): GrpcInboundResponse {
           return GrpcInboundResponse::ok([
               'created' => true,
               'order' => $request->message,
           ]);
       },
   );

   $response = $server->receive('/orders.v1.OrderService/Create', ['order_id' => 1001]);

Host-controlled inbound runtime
-------------------------------

TalkingBytes intentionally does not own a forever-running server loop. A host such
as Foundation can provide a ``GrpcInboundSource`` and call ``serveOne()``
inside its existing worker lifecycle.

.. code-block:: php

   use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
   use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;

   $dispatcher = GrpcInboundDispatcher::new()
       ->withHandler('/orders.v1.OrderService/Create', $handler);

   $served = $dispatcher->serveOne(
       $source,
       CancellationSignal::fromCallable($hostShouldStop),
   );

The source owns waiting for/accepting one native exchange. The accepted exchange
exposes a normalized ``GrpcInboundRequest`` and receives exactly one
``GrpcInboundResponse``. TalkingBytes keeps dispatch/status/error semantics;
the host keeps worker heartbeat, restart, release-generation and process policy.

Inbound streaming scope in 2.2
------------------------------

The host-controlled inbound boundary in 2.1 is deliberately request/response:
one accepted ``GrpcInboundExchange`` exposes one normalized
``GrpcInboundRequest`` and is completed with exactly one
``GrpcInboundResponse``.

TalkingBytes 2.2 does **not** expose a server-side inbound streaming exchange
contract. Client/server/bidirectional streaming support described in
:doc:`streaming` belongs to the outbound/native client adapter boundary and
processes iterables and callbacks incrementally without accumulating a complete
stream in memory. A future inbound streaming contract, if added, must preserve
the same incremental/bounded rule rather than buffering an entire stream.

Method dispatch behavior
------------------------

- unknown methods return ``GrpcStatus::Unimplemented``
- handler exceptions return ``GrpcStatus::Internal`` with a stable public message
- handler exception classes/messages/traces never cross the response boundary by default
- method and deadline are validated on inbound request construction

Inbound events
--------------

- ``grpc.inbound.start``
- ``grpc.inbound.finish``
- ``grpc.inbound.failed``

Inbound observability is emitted through the injected dispatcher. Handler
exception classes may appear in local failed-event diagnostics, but not in the
wire response.
