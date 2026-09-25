TalkingBytes 2.2 Hardening Notes
================================

Scope
-----

TalkingBytes 2.2 is a compatible minor release focused on security boundaries,
protocol correctness, and data-integrity behavior. It does not intentionally
remove an established public API.

Additive API changes
--------------------

- ``HttpRequest::markSensitiveHeader()`` and ``markSensitiveQuery()`` let
  custom authenticators declare non-standard credential fields. The same
  bounded metadata drives redirect stripping and observability redaction.
- ``HttpRequest::sensitiveHeaderNames()`` and ``sensitiveQueryNames()`` expose
  the normalized request sensitivity set.
- ``CookieJar`` adds the optional ``allowedParentDomains`` constructor
  argument. Existing host-only behavior is unchanged; parent-domain sharing
  now requires an explicit allowed scope.

Corrected behavior
------------------

HTTP
~~~~

- Cross-origin redirects remove native and declared custom authentication
  outputs rather than relying on a small fixed header list.
- Strict private-network mode disables inherited cURL proxy environment
  settings and still rejects explicit proxies.
- Redirect cookies are stored against the origin that actually emitted them
  and are recomputed per hop.
- Domain cookies remain opt-in and parent-domain sharing fails closed unless
  the parent is explicitly allowed.
- Buffered and streamed downloads publish atomically only after an accepted
  transfer; failed requests preserve an existing target.
- Valid empty 200/204/304 and empty redirect responses are no longer treated
  as transport failures.

Email and DKIM
~~~~~~~~~~~~~~

- IMAP move fallback requires ``MOVE`` or ``UIDPLUS`` and never uses bare
  ``EXPUNGE``.
- Sendmail submission passes the complete envelope recipient list explicitly,
  including Bcc, while keeping Bcc out of MIME headers.
- Spool consumers atomically claim messages before parsing; a lost claim is
  treated as contention/claim failure rather than malformed mail.
- Ed25519-SHA256 DKIM now signs/verifies the SHA-256 digest required by
  RFC 8463. Historical TalkingBytes signatures made over raw canonical input
  are intentionally not accepted through a compatibility fallback.
- DKIM relaxed empty-body, oversigning, strict key identity, multi-signature,
  and ambiguous DNS-key handling are aligned with verifier policy.

gRPC
~~~~

- Generated server-stream and bidirectional calls finalize through native
  ``getStatus()`` when available, preserving non-OK status and trailers.
- A separate CI lane exercises actual upstream PHP gRPC call objects against a
  localhost peer with pinned native/userland dependencies.
- The current generated bidirectional adapter is explicitly write-then-read.
  Interactive full-duplex scheduling needs a different coordination contract
  and is deferred to a future major version instead of being implied by 2.2.

Webhook
~~~~~~~

- Replay claims are retained for at least the complete remaining signature
  acceptance window, including accepted future timestamps, even when a
  shorter custom replay TTL is requested.

Compatibility and migration
---------------------------

Applications that intentionally share cookies across sibling hosts must list
the accepted parent domain through ``allowedParentDomains``. Custom HTTP
authenticators should mark credential-bearing fields as sensitive. Consumers
that exchange historical TalkingBytes Ed25519 DKIM signatures must regenerate
standards-compliant signatures; verification does not try both the standard
and former non-standard representation.

No exactly-once guarantees are introduced for SMTP, sendmail, spool
application processing, or distributed webhook handling. The corrected
ownership and replay rules are fail-closed transport guarantees within their
documented boundaries.


Platform validation
-------------------

The 2.2 release candidate's process, filesystem, socket, Mailpit, and native
gRPC integration gates run on Ubuntu Linux. Portable code paths retain their
documented fallbacks when optional POSIX/native facilities are absent, but this
release does not claim a separately exercised Windows or macOS native-process
integration matrix. In particular, POSIX sendmail process-group cleanup remains
opportunistic and spool claims that move between directories require a
same-filesystem atomic rename boundary.
