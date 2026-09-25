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
- ``assertSentWhere()`` for custom predicates
- ``lastMessage()`` for direct message inspection

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
- non-destructive peek failures
- atomic claim contention and processing-directory ownership
- crash/restore, collision, symlink, and same-filesystem claim boundaries

Event assertions
----------------

Inject a ``CallableEventDispatcher`` into the sender/receiver/mailbox factory
or parser under test and assert payload shape for ``email.send.*``,
``email.receive.*``, ``email.parse.failed``, ``mailbox.command.*``, and
``bounce.detected``.

``Email::events()`` remains an explicit compatibility facade; normal runtime
graphs do not read process-global listener state.
