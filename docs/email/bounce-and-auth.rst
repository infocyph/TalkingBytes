Bounce and Authentication Parsing
=================================

Bounce parsing
--------------

Use ``BounceParser`` for DSN and plain-text bounce classification.

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Parser\BounceParser;

   $bounceParser = new BounceParser();
   $report = $bounceParser->parse($parsedEmail); // first report convenience
   $reports = $bounceParser->parseMany($parsedEmail); // all recipients

Bounce output uses ``BounceReport`` and ``BounceType``.

Fields include:

- recipient
- action
- status code
- diagnostic code
- remote MTA
- original message id
- classification (hard/soft/mailbox-full/user-unknown/etc.)

Delivery status parser
----------------------

``DeliveryStatusParser`` parses ``message/delivery-status`` blocks and supports
multiple recipient sections.

Authentication-Results parsing
------------------------------

Use ``AuthenticationResultsParser`` to parse inbound auth signals:

- DKIM
- SPF
- DMARC
- ARC

.. code-block:: php

   use Infocyph\TalkingBytes\Email\Parser\AuthenticationResultsParser;

   $results = (new AuthenticationResultsParser())
       ->parse('mx.example.net; dkim=pass header.d=example.com; spf=pass smtp.mailfrom=example.com');

Auth result helpers
-------------------

``AuthenticationResults`` provides convenience methods:

- ``passedDkim()``
- ``passedSpf()``
- ``passedDmarc()``
- ``passedArc()``
- ``isAuthenticated()``
- ``resultFor($method)``

DKIM verification
-----------------

``DkimVerifier`` validates inbound DKIM signatures with resolver abstraction.

Resolvers:

- ``DnsDkimPublicKeyResolver``
- ``StaticDkimPublicKeyResolver``
- ``CachedDkimPublicKeyResolver``

Use ``StaticDkimPublicKeyResolver`` for deterministic tests.
