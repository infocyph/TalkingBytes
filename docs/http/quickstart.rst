HTTP Quick Start
================

Basic JSON request
------------------

.. code-block:: php

   use Infocyph\TalkingBytes\Http\HttpClient;

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

Client shortcuts
----------------

``HttpClient`` helpers include:

- ``get()``, ``head()``, ``options()``, ``delete()``
- ``postJson()``, ``putJson()``, ``patchJson()``
- ``postForm()``
- ``postRaw()``

Configuration entrypoints
-------------------------

- ``HttpClient::curl()``
- ``HttpClient::multi($maxConcurrency)``
- ``HttpClient::fake()``
- ``HttpClient::fromConfig(HttpClientConfig::fromArray(...))``
