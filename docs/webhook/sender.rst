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
