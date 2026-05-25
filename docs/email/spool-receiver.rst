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

- may move to processing directory before parse
- on success can delete or move-after-read
- on parse/read failure can quarantine to failed directory
- optional lock-before-read for worker safety

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
- quarantine is best-effort if filesystem operations fail
