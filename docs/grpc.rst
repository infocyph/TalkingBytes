gRPC
====

Overview
--------

TalkingBytes gRPC is an adapter-oriented layer.

It provides:

- validated ``GrpcRequest`` and ``GrpcMetadata``
- ``GrpcTransport`` result mapping to ``CommunicationResult``
- retry policy for transient status codes
- native invoker bridge for generated gRPC clients
- fake/assertable callers for tests

Basic call
----------

.. code-block:: php

   $result = \Infocyph\TalkingBytes\Grpc\GrpcClient::transport($transport)
       ->call(\Infocyph\TalkingBytes\Grpc\GrpcRequest::create(
           'OrderService/CreateOrder',
           ['order_id' => 1001],
       ));

   if ($result->successful) {
       $payload = $result->response->payload;
   }

Retry
-----

.. code-block:: php

   $client = \Infocyph\TalkingBytes\Grpc\GrpcClient::transport($transport)
       ->withGrpcRetry(
           \Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy::standard(attempts: 3)
       );

The retry policy targets transient gRPC status codes.

Native invoker bridge
---------------------

``GrpcClient::usingNative()`` accepts a ``NativeGrpcInvoker`` implementation to bridge generated/ext-grpc calls without coupling core code to a specific client package.

Testing
-------

``FakeGrpcCaller`` and ``AssertableGrpcCaller`` support:

- scripted success/failure calls
- call count assertions
- method assertions
- payload/metadata assertions

Events
------

gRPC lifecycle events include:

- ``grpc.request.start``
- ``grpc.request.finish``
- ``grpc.request.failed``
- ``grpc.retry``
