IMAP Mailbox
============

Overview
--------

Use ``Email::mailbox()->usingImap(ImapConfig)`` to work with foldered IMAP mailboxes.

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Config\ImapConfig;
   use Infocyph\TalkingBytes\Email\Email;

   $mailbox = Email::mailbox()->usingImap(
       new ImapConfig('imap.example.com', username: 'user', password: 'secret')
   );

Folder operations
-----------------

Mailbox-level methods:

- ``folders()`` and ``folderDetails()``
- ``folder($name)``
- ``status($name)``
- ``createFolder($name)``, ``renameFolder($from, $to)``, ``deleteFolder($name)``
- ``subscribeFolder($name)``, ``unsubscribeFolder($name)``
- ``folderExists($name)``
- ``archive($sourceFolder, $uid, ?$archiveFolder = null)``

Folder object operations
------------------------

From ``$inbox = $mailbox->folder('INBOX')``:

- fetch: ``fetchRaw($uid)``, ``fetchParsed($uid)``, ``fetchHeaders($uid)``, ``fetchSummary($uid)``, ``fetchAttachments($uid)``, ``fetchBodyStructure($uid)``
- flags: ``markSeen()``, ``markUnread()``, ``addFlag()``, ``removeFlag()``
- message ops: ``copy()``, ``move()``, ``delete()``, ``expunge()``
- batch ops: ``markSeenMany()``, ``markUnreadMany()``, ``copyMany()``, ``moveMany()``, ``deleteMany()``, ``addFlagMany()``, ``removeFlagMany()``
- mailbox maintenance: ``status()``, ``close()``, ``watch()`` (if transport supports IDLE)

Search/query
------------

Build queries with ``MailboxSearch``.

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Mailbox\MailboxSearch;

   $refs = $inbox->query(
       MailboxSearch::new()
           ->unseen()
           ->from('billing@example.com')
           ->subjectContains('invoice')
           ->newestFirst()
           ->limit(25)
   );

Criteria highlights:

- dates: ``before()``, ``on()``, ``since()``
- headers/content: ``from()``, ``to()``, ``cc()``, ``bcc()``, ``subjectContains()``, ``bodyContains()``, ``textContains()``
- flags: ``seen()``, ``unseen()``, ``answered()``, ``unanswered()``, ``flagged()``, ``unflagged()``, ``deleted()``, ``undeleted()``
- size: ``largerThan()``, ``smallerThan()``
- UID and keywords: ``uidRange()``, ``keyword()``, ``unkeyword()``
- attachment hint: ``hasAttachment()``

Cost controls
-------------

``MailboxSearch`` includes safeguards for expensive operations:

- ``maxSummaryFetches($n)``
- ``maxClientSideFilterFetches($n)``
- ``requireExplicitLimitForExpensiveSearch(true)``

These protect sort/filter paths that require extra fetches.

Security/validation
-------------------

IMAP commands use guards for:

- folder names (control char and length checks)
- UIDs (must be positive)
- part numbers for ``BODY.PEEK`` section fetches
- custom flags (injection-safe format)

Errors
------

Common exceptions:

- ``MailboxConnectionException``
- ``MailboxAuthenticationException``
- ``MailboxProtocolException``
- ``MailboxException`` base type
