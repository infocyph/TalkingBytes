Inbound Parser
==============

Primary parser
--------------

``RawEmailParser`` parses raw RFC822 messages into ``ParsedEmail``.

Pipeline components:

- ``HeaderParser``
- ``AddressParser``
- ``MimeParser`` / ``MimePartParser``
- ``TransferDecoder``
- ``CharsetDecoder``
- ``AttachmentExtractor``

Basic parse
-----------

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;

   $parser = new RawEmailParser();
   $parsed = $parser->parse($rawEml, ['source' => 'api']);

Parsed output
-------------

``ParsedEmail`` includes:

- address lists: ``from``, ``to``, ``cc``, ``bcc``
- headers and helper accessors (``header()``, ``headers()``)
- ``subject``, ``messageId``, ``inReplyTo``, ``references``
- ``textBody`` and ``htmlBody``
- flat MIME part list in ``parts``
- ``attachments`` as ``ReceivedAttachment``
- ``metadata`` passthrough

Convenience helpers:

- ``hasAttachments()``
- ``attachmentCount()``
- ``firstAttachment()``
- ``fromEmail()``
- ``subjectOrEmpty()``
- ``isReply()``
- ``isBounceCandidate()``

Attachment object
-----------------

``ReceivedAttachment`` supports:

- ``contents()``
- ``saveTo($path)``
- ``streamTo($resource)``
- ``safeFilename()``
- ``isInline()`` and ``isImage()``
- ``extension()``
- ``contentHash($algorithm = 'sha256')``

Limits
------

Parsing is bounded by ``EmailLimits``:

- max raw message bytes
- max header bytes/count
- max MIME depth/parts
- max decoded body bytes
- max attachment count/size

Exceeding limits raises ``EmailParseException``.

Header behaviors
----------------

- duplicate headers are preserved in inbound header bags
- folded headers are unfolded
- encoded words are decoded with tolerant fallback
- malformed shapes are tolerated where possible (parser safety first)
