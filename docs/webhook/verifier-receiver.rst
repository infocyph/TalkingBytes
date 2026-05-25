Verifier and Receiver
=====================

Verifier
--------

``WebhookVerifier`` validates:

- signature header presence/shape
- timestamp validity and tolerance window
- HMAC value with constant-time ``hash_equals``

Signature format is ``t=<timestamp>,v1=<hmac>``.

Verification result
-------------------

``WebhookVerificationResult`` exposes:

- valid flag
- reason code (for rejection paths)
- parsed timestamp metadata
- redacted signature context (no secret leakage)

Receiver
--------

``WebhookReceiver`` performs:

1. verify signature
2. decode JSON payload
3. validate event + delivery id
4. optional replay store check
5. return ``WebhookEvent``

``WebhookEvent`` fields:

- event
- delivery id
- payload
- timestamp
- normalized headers/metadata
