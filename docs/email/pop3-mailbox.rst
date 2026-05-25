POP3 Mailbox
============

Overview
--------

POP3 is intentionally exposed through ``Pop3Mailbox`` (not the IMAP foldered API).

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Config\Pop3Config;
   use Infocyph\TalkingBytes\Email\Email;

   $pop3 = Email::mailbox()->usingPop3(
       new Pop3Config('pop.example.com', username: 'user', password: 'secret')
   );

Capabilities
------------

- ``status()``
- ``listMessageRefs(?int $limit = null)``
- ``fetchRaw(int $messageNumber)``
- ``fetchParsed(int $messageNumber)``
- ``delete(int $messageNumber)``
- ``receiveOldest()`` and ``receiveNewest()``
- ``reset()`` (RSET)
- ``logout()``

UIDL support
------------

``listMessageRefs()`` populates ``MailboxMessageRef::externalId`` from POP3 UIDL.
Use UIDL when you need stable external identity across sessions.

Message-number guard
--------------------

POP3 command targets are validated via ``Pop3MessageNumberGuard``:

- message number must be positive
- avoids invalid/injection-prone command values

Protocol limitations
--------------------

POP3 limitations are expected:

- no folders
- no seen/unseen flag model
- no server-side rich search equivalent to IMAP
- message numbers can shift after deletions/commit

Operational notes
-----------------

- deletion is committed on ``QUIT`` in typical POP3 flows
- ``reset()`` can clear deletion marks before commit
- multiline response parsing includes dot-termination and dot-unstuffing handling
