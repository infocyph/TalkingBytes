Getting Started
===============

Requirements
------------

- PHP ``>=8.4``
- ``ext-curl``
- ``ext-fileinfo``
- ``ext-openssl``

Install
-------

.. code-block:: bash

   composer require infocyph/talkingbytes

Namespace
---------

.. code-block:: php

   use Infocyph\TalkingBytes\Http\HttpClient;

Quick examples
--------------

HTTP send (cURL)
~~~~~~~~~~~~~~~~

.. code-block:: php

   $result = HttpClient::curl()
       ->withBearerToken($token)
       ->timeout(10)
       ->postJson('https://api.example.com/orders', [
           'order_id' => 1001,
           'amount' => 500,
       ]);

   if ($result->successful) {
       $data = $result->response->json();
   }

gRPC send
~~~~~~~~~

.. code-block:: php

   use Infocyph\TalkingBytes\Grpc\GrpcClient;
   use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
   use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
   use Infocyph\TalkingBytes\Grpc\GrpcStatus;

   $client = GrpcClient::using(
       static fn (GrpcRequest $request): GrpcResponse =>
           new GrpcResponse(GrpcStatus::Ok, ['echo' => $request->message]),
   );

   $result = $client->send(new GrpcRequest(
       '/orders.v1.OrderService/Create',
       ['order_id' => 1001],
   ));

Webhook receive
~~~~~~~~~~~~~~~

.. code-block:: php

   $event = \Infocyph\TalkingBytes\Webhook\Webhook::receiver('whsec_test')
       ->receive($rawBody, $headers);

Email send
~~~~~~~~~~

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
   use Infocyph\TalkingBytes\Email\Config\SmtpCredentials;
   use Infocyph\TalkingBytes\Email\Email;
   use Infocyph\TalkingBytes\Email\EmailMessage;
   use Infocyph\TalkingBytes\Email\Enum\SmtpSecurity;

   $smtp = new SmtpConfig(
       host: 'smtp.example.com',
       port: 587,
       security: SmtpSecurity::StartTlsRequired,
       credentials: new SmtpCredentials('user@example.com', 'secret'),
   );

   $result = Email::sender()->usingSmtp($smtp)->send(
       EmailMessage::new()
           ->from('sender@example.com')
           ->to('receiver@example.com')
           ->subject('Hello')
           ->text('Hello from TalkingBytes')
   );
