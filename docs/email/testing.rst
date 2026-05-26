Email Testing
=============

Outbound fake transport
-----------------------

Create a fake sender:

.. code-block:: php

   $sender = \Infocyph\TalkingBytes\Email\Emailer::fake();

   $sender->send($message);

   $sender->assertable()->assertSentCount(1);

Available assertion helpers include:

- ``assertSent()``
- ``assertNothingSent()``
- ``assertSentCount()``
- ``assertSentTo()``
- ``assertSentFrom()``
- ``assertSentSubject()``
- ``assertHasAttachment()``
- ``assertHasInlineAttachment()``
- ``assertHeader()``
- ``assertBodyContains()``

Mailbox testing
---------------

Use ``FakeMailbox``/``FakeMailboxTransport`` for mailbox behavior in tests.

Parser testing
--------------

The suite covers:

- raw MIME nesting and transfer decoding
- duplicate/folded/encoded headers
- charset edge cases
- attachment extraction and filename handling
- parser limit enforcement

Spool testing
-------------

Spool tests include:

- peek/receive/receiveMany
- delete/move/failed quarantine behavior
- lock and processing directory paths
- concurrent-safe file handling assumptions

Event assertions
----------------

Attach a listener via ``Email::events($listener)`` and assert event payload
shape for ``email.send.*``, ``email.receive.*``, ``email.parse.failed``,
``mailbox.command.*``, and ``bounce.detected``.
