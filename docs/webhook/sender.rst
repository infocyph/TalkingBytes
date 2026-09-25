Webhook Sender
==============

Message object
--------------

``WebhookMessage`` fields:

- URL
- event name
- payload (array/json/raw-json string)
- delivery id
- custom headers (non-reserved)
- metadata tags

Retry profile
-------------

``WebhookRetryProfile`` controls attempt count and delay strategy.

- transient status retry behavior
- retry-after support
- attempt header tracking (``X-TB-Attempt``)
- stable delivery id across retries

Result model
------------

``WebhookDeliveryResult`` includes:

- delivery id
- event name
- target URL
- attempts
- delivered boolean
- final status/error metadata

Reserved headers
----------------

Reserved webhook headers are sender-controlled and protected from override
so event/delivery/signature semantics stay authoritative.

Signature interoperability
--------------------------

Signed native deliveries use ``t=<timestamp>,v2=<hmac>``. For HMAC-SHA256,
the signed bytes are the decimal timestamp, a dot, and the following bytes:

.. code-block:: text

   talkingbytes.webhook.v2<NUL>event<NUL>deliveryId<NUL>rawBody

``<NUL>`` denotes one zero byte. Event and delivery ID cannot contain control
characters; the body is included exactly as transmitted, without JSON
re-encoding. Custom ``WebhookSigner`` implementations receive this bound payload
and the timestamp. The native receiver verifies HMAC-SHA256.

``WebhookSignature::buildHeader($body, $timestamp, $event, $deliveryId)``
produces the same native signature. Its two-argument form retains legacy
body/timestamp-only ``v1`` signing for direct integrations.

Security upgrade from 2.0
-------------------------

Upgrade senders and receivers together. TalkingBytes 2.1 and later receivers
reject legacy ``v1`` deliveries, and 2.0 receivers do not understand ``v2``. This is an
intentional security compatibility correction: accepting unsigned delivery IDs
allows replay protection to be bypassed, and unsigned event names allow event
substitution. There is no automatic downgrade or insecure receiver opt-out.
External senders must implement the byte format above before switching traffic.
