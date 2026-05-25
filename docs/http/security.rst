HTTP Security
=============

URL and header validation
-------------------------

Requests reject unsafe inputs:

- non-http/https schemes
- control characters and CRLF injection shapes
- invalid header names/values

SSRF controls
-------------

Available request controls:

- ``allowHosts(array $hosts)``
- ``blockHosts(array $hosts)``
- ``blockPrivateNetworks(bool $enabled = true)``

Redirect safety
---------------

When redirects are enabled, destination checks still apply.
Redirect policy defaults to disabled for safer API behavior.

TLS and certificate controls
----------------------------

- peer/host verification enabled by default
- optional CA bundle path and client certificate/key support
- explicit insecure mode exists and should be avoided in production

Redaction
---------

``HttpRedactor`` masks sensitive data in events/log payloads,
including common auth headers and query secrets.
