Verifier and Receiver
=====================

Verifier
--------

``WebhookVerifier`` validates:

- signature header presence/shape
- timestamp validity and tolerance window
- HMAC value with constant-time ``hash_equals``

Native delivery signature format is ``t=<timestamp>,v2=<hmac>``. The signature
binds the timestamp, event, delivery ID, and exact raw body. Receivers require
``v2`` and never fall back to ``v1``.

For direct verification, pass ``event`` and ``deliveryId`` to
``verifyResult()``. Without those arguments, the low-level verifier retains
legacy ``v1`` body/timestamp verification only; it does not authenticate routing
or replay identity.

Verification result
-------------------

``WebhookVerificationResult`` exposes:

- ``valid`` and rejection ``reason``
- parsed ``timestamp``
- ``signaturePresent`` and a short ``signaturePrefix`` diagnostic
- metadata including ``max_age_seconds``; successful verification also records
  ``signature_count`` and ``verified_at``

Protocol events still redact signature values. The verification-result prefix
is diagnostic context for the direct caller and is not the signing secret.

Receiver
--------

``WebhookReceiver`` performs:

1. validate event + delivery id
2. verify the signature including those fields
3. decode JSON payload
4. optional replay store check
5. return ``WebhookEvent``

``WebhookEvent`` fields:

- event
- delivery id
- payload
- timestamp
- normalized headers/metadata
