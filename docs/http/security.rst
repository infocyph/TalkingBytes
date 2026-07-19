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

Cookie safety
-------------

``CookieJar`` validates cookie syntax, rejects ``Domain`` attributes that do
not match the response origin, honors RFC path boundaries, and bounds retained
state. The default capacity is 3,000 cookies and can be lowered with the
``maxCookies`` constructor argument for long-lived clients.
