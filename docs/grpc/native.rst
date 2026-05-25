Native Invoker Bridge
=====================

Purpose
-------

``GrpcClient::usingNative()`` provides a boundary for generated/ext-grpc
clients while keeping core module protocol-agnostic.

Interfaces
----------

- ``NativeGrpcInvoker``
- ``NativeGrpcResult``
- ``NativeGrpcStreamingInvoker``
- ``GeneratedStubGrpcInvoker`` (built-in adapter)

Behavior
--------

The invoker adapter is responsible for:

- calling native gRPC stubs
- mapping native status/payload/metadata
- deadline conversion expectations

This keeps generated-client specifics out of the shared communication core.

Generated stub adapter
----------------------

``GeneratedStubGrpcInvoker`` adapts generated ``grpc/grpc`` stub clients using
duck-typed call objects (``wait()``, ``responses()``/``read()``, ``write()``).

.. code-block:: php

   use Infocyph\TalkingBytes\Grpc\GrpcClient;
   use Infocyph\TalkingBytes\Grpc\Native\GeneratedStubGrpcInvoker;

   $stub = new \Orders\OrderServiceClient('orders.internal:443', [
       'credentials' => \Grpc\ChannelCredentials::createSsl(),
   ]);

   $adapter = new GeneratedStubGrpcInvoker($stub, [
       '/orders.v1.OrderService/Create' => 'Create',
   ]);

   $client = GrpcClient::usingNativeStreaming($adapter, $adapter);

Method map keys are gRPC method paths; values are PHP stub method names.
