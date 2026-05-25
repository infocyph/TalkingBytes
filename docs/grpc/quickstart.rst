gRPC Quick Start
================

Basic call
----------

.. code-block:: php

   use Infocyph\TalkingBytes\Grpc\GrpcClient;
   use Infocyph\TalkingBytes\Grpc\GrpcRequest;

   $result = GrpcClient::transport($transport)
       ->call(GrpcRequest::create('Orders/Create', ['order_id' => 1001]));

   if ($result->successful) {
       $payload = $result->response->payload;
   }

Request model
-------------

``GrpcRequest`` validates:

- method format
- metadata keys/values
- deadline bounds

Response model
--------------

``GrpcResponse`` includes:

- ``status`` (``GrpcStatus``)
- payload
- headers and trailers metadata

Failures are mapped into ``CommunicationResult`` with error metadata.
