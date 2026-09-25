Spool Receiver
==============

Overview
--------

``SpoolEmailReceiver`` reads ``.eml`` files from a spool directory and parses
messages via ``EmailParser`` (default ``RawEmailParser``).

Create receiver
---------------

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
   use Infocyph\TalkingBytes\Email\Email;

   $receiver = Email::receiver()->usingSpool(
       new SpoolConfig(
           directory: '/var/mail/inbound',
           processingDirectory: '/var/mail/processing',
           lockBeforeRead: true,
           maxMessages: 50,
           olderThanSeconds: 2,
       ),
       deleteAfterRead: true,
       moveAfterRead: '/var/mail/processed',
       failedDirectory: '/var/mail/failed',
   );

Methods
-------

- ``peek()`` reads next message without consuming it.
- ``receive()`` receives one parsed email.
- ``receiveParsed()`` same as ``receive()``.
- ``receiveMany(?int $limit = null)`` receives in batch.

Consumption behavior
--------------------

Depending on config/options:

- consuming reads atomically claim the source before parsing
- a configured processing directory is used only when the claim can remain an
  atomic same-filesystem rename
- on success a consumed claim can be deleted, moved-after-read, or restored
- parse/read failure can quarantine only a claim owned by that consuming read
- ``peek()`` never claims, deletes, moves, or quarantines the source on failure
- ``lockBeforeRead`` protects an individual file read; exclusive consumption is
  established by the atomic claim, not by the read lock

Spool metadata
--------------

Parsed email metadata includes spool fields:

- ``source = spool``
- ``path`` / ``original_path``
- ``processing_path``
- ``consumed_at``
- ``size_bytes``

Failure handling
----------------

- read/parse failures trigger ``email.parse.failed`` and ``email.receive.finish`` with error metadata
- destructive failure handling is restricted to an owned consume claim;
  failed ``peek()`` operations leave the source untouched
- quarantine is best-effort if filesystem operations fail

Exclusive consumption
---------------------

``receive()`` / ``receiveParsed()`` atomically claim the selected source before
parsing. A configured ``processingDirectory`` is used when it is on the same
filesystem as the source; otherwise the claim fails safely rather than relying
on a non-atomic cross-filesystem move. Without a processing directory,
TalkingBytes uses a hidden in-place claim whose name no longer matches the
spool extension. Competing consumers that lose the claim treat it as
contention and do not quarantine the message. ``peek()`` remains strictly
non-consuming, including on oversized, unreadable, or parser-rejected input. A
process crash can leave a claim file requiring operational recovery; this is
at-least-once transport ownership, not an exactly-once application-processing
guarantee.
