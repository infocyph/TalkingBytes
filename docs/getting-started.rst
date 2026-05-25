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

   $result = \Infocyph\TalkingBytes\Grpc\GrpcClient::transport($transport)
       ->call(\Infocyph\TalkingBytes\Grpc\GrpcRequest::create(
           'OrderService/CreateOrder',
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

   $smtp = \Infocyph\TalkingBytes\Email\Config\SmtpConfig::fromArray([
       'host' => 'smtp.example.com',
       'port' => 587,
       'security' => \Infocyph\TalkingBytes\Email\Enum\SmtpSecurity::StartTls,
       'credentials' => [
           'username' => 'user@example.com',
           'password' => 'secret',
       ],
   ]);

   $result = \Infocyph\TalkingBytes\Email\Email::sender()
       ->usingSmtp($smtp)
       ->send(
           \Infocyph\TalkingBytes\Email\EmailMessage::make()
               ->from('sender@example.com')
               ->to('receiver@example.com')
               ->subject('Hello')
               ->text('Hello from TalkingBytes')
       );
