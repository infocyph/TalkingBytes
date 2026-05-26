Requests and Bodies
===================

Request model
-------------

``HttpRequest`` is immutable and validates URL/header safety.

Factories:

- ``get()``, ``post()``, ``put()``, ``patch()``, ``delete()``, ``head()``, ``options()``

Header helpers
--------------

- ``header($name, $value)``
- ``headers(array $headers)``
- ``withoutHeader($name)``
- ``acceptJson()``, ``contentType()``, ``userAgent()``

Query helpers
-------------

- ``query($name, $value)``
- ``queries(array $pairs)``
- ``withoutQuery($name)``

Body types
----------

- JSON: ``json($payload, $flags = 0)``
- Form: ``form($payload)``
- Raw: ``raw($body, $contentType)``
- Multipart: ``multipart()->field()->file()->data()->stream()``

Auth helpers
------------

At client level:

- ``withBasicAuth()``
- ``withBearerToken()``
- ``withApiKey()``
- ``withQueryAuth()``
- ``withAuthenticator()``

Signing
-------

Use ``withSigner(new HmacSha256Signer(...))`` or
``SignedRequestAuth`` depending on integration style.
