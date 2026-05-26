Email Quick Start
=================

Facade entry points
-------------------

- ``Email::sender()`` for outbound delivery
- ``Email::receiver()`` for inbound source readers (currently spool)
- ``Email::mailbox()`` for network mailbox operations (IMAP, POP3)

Minimal outbound
----------------

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Email;
   use Infocyph\TalkingBytes\Email\EmailMessage;

   $result = Email::sender()->usingNull()->send(
       EmailMessage::new()
           ->from('sender@example.com')
           ->to('user@example.com')
           ->subject('Hello')
           ->text('Hello from TalkingBytes')
   );

SMTP outbound
-------------

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
   use Infocyph\TalkingBytes\Email\Config\SmtpCredentials;
   use Infocyph\TalkingBytes\Email\Enum\SmtpSecurity;

   $smtp = new SmtpConfig(
       host: 'smtp.example.com',
       port: 587,
       security: SmtpSecurity::StartTlsRequired,
       credentials: new SmtpCredentials('user@example.com', 'secret'),
   );

   $sender = Email::sender()->usingSmtp($smtp);

Spool inbound receive
---------------------

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Config\SpoolConfig;

   $receiver = Email::receiver()->usingSpool(new SpoolConfig('/var/mail/inbound'));
   $email = $receiver->receive();

   if ($email !== null) {
       $subject = $email->subject;
   }

IMAP mailbox
------------

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Config\ImapConfig;

   $mailbox = Email::mailbox()->usingImap(
       new ImapConfig('imap.example.com', username: 'user', password: 'secret')
   );

   $inbox = $mailbox->folder('INBOX');
   $refs = $inbox->query();

POP3 mailbox
------------

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Config\Pop3Config;

   $pop3 = Email::mailbox()->usingPop3(
       new Pop3Config('pop.example.com', username: 'user', password: 'secret')
   );

   $refs = $pop3->listMessageRefs(limit: 10);
