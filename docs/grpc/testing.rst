gRPC Testing
============

Utilities
---------

- ``FakeGrpcCaller``
- ``AssertableGrpcCaller``

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
