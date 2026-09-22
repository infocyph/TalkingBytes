gRPC Streaming
==============

Overview
--------

TalkingBytes supports streaming through the native invoker boundary.

To enable it, create client with both unary and streaming native invokers:

.. code-block:: php

   $client = GrpcClient::usingNativeStreaming($unaryInvoker, $streamingInvoker);

Check support
-------------

.. code-block:: php

   if (!$client->supportsStreaming()) {
       // fallback path
   }

Server stream
-------------

.. code-block:: php

   $result = $client->serverStream(
       new GrpcRequest('Orders/Stream', ['cursor' => 1]),
       static function (mixed $message): void {
           // handle each streamed response message
       }
   );

Client stream
-------------

.. code-block:: php

   $result = $client->clientStream(
       method: 'Orders/Upload',
       messages: $payloadIterator,
   );

Bidirectional stream
--------------------

.. code-block:: php

   $result = $client->bidiStream(
       method: 'Orders/Bidi',
       messages: $outboundIterator,
       onMessage: static function (mixed $incoming): void {
           // handle inbound stream messages
       },
   );

Binary metadata
---------------

Use explicit binary metadata APIs:

- ``withBinaryValue('trace-bin', $bytes)``
- ``withBinary('trace-bin', [...])``
- ``binaryValues('trace-bin')``
- ``firstBinary('trace-bin')``

Non-binary metadata continues to use ``withValue()``, ``with()``, and ``values()``.

Generated-stream cancellation
-----------------------------

``GeneratedStubGrpcInvoker`` accepts an optional ``CancellationSignal``.
``GrpcClient::usingGeneratedStub()`` exposes the same optional cancellation
argument.

Cancellation is checked between outbound writes and inbound response
deliveries. When cancellation or a consumer callback failure is observed,
TalkingBytes invokes the native call object's ``cancel()`` method when
available before propagating the failure. Streaming remains incremental; the
adapter does not buffer a complete stream in memory.
