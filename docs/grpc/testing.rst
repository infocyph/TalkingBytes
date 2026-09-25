gRPC Testing
============

Utilities
---------

- ``FakeGrpcCaller``
- ``AssertableGrpcCaller``
- ``FakeGrpcInboundSource``
- ``FakeGrpcInboundExchange``

Generated-stub tests use native-shaped unary/client/server/bidirectional call
doubles. A separate optional integration lane exercises actual upstream PHP
gRPC call objects against a localhost peer.

Example
-------

.. code-block:: php

   use Infocyph\TalkingBytes\Grpc\GrpcClient;
   use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
   use Infocyph\TalkingBytes\Grpc\Testing\FakeGrpcCaller;

   $fake = (new FakeGrpcCaller())->pushOk(['ok' => true]);

   $client = GrpcClient::using($fake);
   $client->send(new GrpcRequest('/orders.v1.OrderService/Create', ['order_id' => 1]));

   $fake->assert()->assertCallCount(1);

Assertions cover method, payload, metadata, and call count behavior.


Inbound runtime testing
-----------------------

Queue a normalized inbound request with ``FakeGrpcInboundSource::enqueue()``,
run one host cycle through ``GrpcInboundDispatcher::serveOne()``, then inspect
the returned fake exchange for completion and response status/message. This keeps
worker-loop tests deterministic without starting a socket server.
