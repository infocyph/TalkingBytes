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
   use Infocyph\TalkingBytes\Grpc\GrpcRequest;
   use Infocyph\TalkingBytes\Grpc\Testing\FakeGrpcCaller;

   $fake = (new FakeGrpcCaller())->pushOk(['ok' => true]);

   $client = GrpcClient::using($fake);
   $client->call(GrpcRequest::create('Orders/Create', ['order_id' => 1]));

   $fake->assert()->assertCallCount(1);

Assertions cover method, payload, metadata, and call count behavior.
