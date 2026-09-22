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

Stream call shape is resolved once from public method reflection when the
adapter is constructed. TalkingBytes never invokes a stream method merely to
probe its signature, and a ``TypeError`` raised inside a generated/user stub is
therefore never treated as a reason to invoke the method a second time.

Explicit method maps are normalized and validated when the adapter is
constructed. Missing, non-public, or invalid mapped methods fail before the
first protocol call.

.. code-block:: php

   use Infocyph\TalkingBytes\Grpc\GrpcClient;

   $stub = new \Orders\OrderServiceClient('orders.internal:443', [
       'credentials' => \Grpc\ChannelCredentials::createSsl(),
   ]);

   $client = GrpcClient::usingGeneratedStub($stub, [
       '/orders.v1.OrderService/Create' => 'Create',
   ]);

Method map keys are gRPC method paths; values are PHP stub method names.
