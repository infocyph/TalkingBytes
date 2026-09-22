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

Fallback behavior
-----------------

Some parser/mailbox/process helpers degrade gracefully when optional extensions are unavailable. Sendmail process-group isolation is opportunistic; direct-child termination remains the portable fallback when POSIX functions are absent or cannot isolate the child.
