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
- ``ext-iconv`` as charset fallback when mbstring is unavailable
- ``ext-imap`` for optional address parsing and UTF7-IMAP fallback helpers

Fallback behavior
-----------------

Some parser/mailbox helpers degrade gracefully when optional extensions are unavailable. Check test skips in CI logs to detect extension-limited environments.
