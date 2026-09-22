Extension Policy
================

Required extensions
-------------------

From ``composer.json``:

- ``ext-curl``
- ``ext-fileinfo``
- ``ext-openssl``

Suggested extensions/packages
-----------------------------

- ``ext-grpc`` for native gRPC transport
- ``grpc/grpc`` for generated PHP gRPC clients
- ``ext-mbstring`` for robust charset conversion
- ``ext-posix`` for best-effort Unix sendmail process-group cleanup
- ``ext-iconv`` as charset fallback when mbstring is unavailable
- ``ext-imap`` for optional address parsing and UTF7-IMAP fallback helpers
- ``ext-sodium`` only for Ed25519-SHA256 DKIM signing and verification

``ext-pcntl`` is deliberately neither required nor suggested. TalkingBytes does
not install signal handlers or use fork-based protocol concurrency. Worker and
supervisor signal ownership belongs to the host runtime.

Cold capability behavior
------------------------

Optional capabilities are selected lazily.

- HTTP and webhook graphs do not require or initialize native gRPC packages.
- basic outbound email and SMTP composition do not require IMAP, POSIX, PCNTL,
  or Sodium.
- RSA-SHA256 DKIM uses OpenSSL and does not require Sodium.
- Ed25519-SHA256 DKIM checks for Sodium only when that algorithm is selected and
  fails with a clear runtime error when it is unavailable.
- generated/native gRPC adapters are not probed by unrelated HTTP, webhook, or
  email graphs.
- IMAP helper fallbacks are evaluated only by mailbox code that needs them.
- POSIX sendmail process-group hardening is opportunistic; the portable direct
  child termination path remains available when POSIX functions are absent.

The CI optional-capability coldness gate runs with ``ext-grpc``,
``ext-imap``, ``ext-posix``, ``ext-pcntl``, and ``ext-sodium``
disabled and exercises the unrelated protocol graphs.

Fallback behavior
-----------------

Parser, mailbox, and process helpers degrade gracefully when optional extensions
are unavailable. Optional capability failure must remain local to the feature
that was explicitly selected; importing or constructing an unrelated protocol
graph must not trigger extension or package probes.
