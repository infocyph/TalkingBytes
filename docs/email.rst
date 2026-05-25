Email
=====

Overview
--------

Email module includes outbound transports, inbound parser, spool receiver, and mailbox access.

Outbound
--------

Supported transports:

- SMTP
- sendmail
- PHP ``mail()``
- spool directory
- log transport
- null transport
- fake/assertable transport

Features:

- MIME text/html/attachments/inline CID
- DKIM signing
- DSN controls
- retry, fallback, logging, and rate-limit decorators
- streaming build/send paths for large payloads

Inbound parser
--------------

Parser stack includes:

- raw email parse
- header/address/MIME parsers
- transfer decoding
- charset decoding
- attachment extraction
- parser limits (size/depth/count)

Spool receiver
--------------

Spool receiver supports:

- ``peek()``
- ``receive()`` / ``receiveMany()``
- move/delete/failed quarantine behavior
- parser-backed parsed email output

Mailbox
-------

IMAP:

- folder list/select/status
- UID search/fetch
- header/raw/parsed fetch modes
- flags, copy/move/delete operations
- lazy part fetch support

POP3:

- message refs/list/fetch/delete
- UIDL support
- multiline response handling and dot-unescaping

Bounce and auth parsing
-----------------------

Included inbound analysis tools:

- ``BounceParser`` and ``DeliveryStatusParser``
- ``Authentication-Results`` parser
- DKIM verification with resolver abstraction

Facade entry points
-------------------

- ``Email::sender()`` for outbound
- ``Email::receiver()`` for one-by-one inbound source
- ``Email::mailbox()`` for network mailbox operations

Examples
--------

See ``README.md`` for end-to-end SMTP, IMAP, POP3, bounce, and parser examples.
