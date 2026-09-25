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
the native call shapes rather than one synthetic interface. Unary and
client-streaming completion use ``wait()``; server-streaming and bidirectional
completion use ``getStatus()``. Inbound stream messages are drained through
``responses()`` or ``read()``. Client-streaming and bidirectional calls use
``write()`` and close the write side through ``writesDone()`` or ``closeWrite()``
when the native call exposes those methods.

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

Native interoperability lane
----------------------------

The repository includes an opt-in localhost interoperability test for the
actual upstream PHP call objects. CI pins ``grpc/grpc`` to 1.82.0,
``google/protobuf`` to 4.33.6 and the Python peer to 1.82.0. The runner uses
its current native ``ext-grpc`` build and records the loaded extension version
in the job output.
The lane exercises unary, server-streaming, client-streaming and bidirectional
call shapes plus native deadline and cancellation cleanup. It is intentionally
separate from the minimal optional-capability job so gRPC remains a cold
optional dependency for ordinary installs.
