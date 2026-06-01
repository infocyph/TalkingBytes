gRPC Inbound and Outbound
=========================

Scope
-----

TalkingBytes now supports both directions:

- outbound client calls via ``GrpcClient``
- inbound request dispatch via ``GrpcServer``

Module layout
-------------

- ``src/Grpc/GrpcClient.php`` outbound entrypoint
- ``src/Grpc/Sender/*`` outbound request/response/transport models
- ``src/Grpc/GrpcServer.php`` inbound entrypoint
- ``src/Grpc/Receiver/*`` inbound request/response/handler models

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
   use Infocyph\TalkingBytes\Grpc\GrpcServer;
   use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
   use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;

   $receiver = GrpcServer::new()->withHandler(
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
   use Infocyph\TalkingBytes\Grpc\GrpcServer;

   $server = GrpcServer::new()->withHandler(
       '/orders.v1.OrderService/Create',
       static function (GrpcInboundRequest $request): GrpcInboundResponse {
           return GrpcInboundResponse::ok([
               'created' => true,
               'order' => $request->message,
           ]);
       },
   );

   $response = $server->receive('/orders.v1.OrderService/Create', ['order_id' => 1001]);

Method dispatch behavior
------------------------

- unknown methods return ``GrpcStatus::Unimplemented``
- handler exceptions return ``GrpcStatus::Internal``
- method and deadline are validated on inbound request construction

Inbound events
--------------

- ``grpc.inbound.start``
- ``grpc.inbound.finish``
- ``grpc.inbound.failed``

This makes inbound request processing observable with the same shared event bus.
