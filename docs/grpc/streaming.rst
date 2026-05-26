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
