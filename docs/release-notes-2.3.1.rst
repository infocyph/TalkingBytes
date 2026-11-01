TalkingBytes 2.3.1 — Runwire 3 compatibility and hardening
===========================================================

Release scope
-------------

This is the maintenance-release candidate for TalkingBytes 2.3.1. It preserves
the 2.3 public protocol interfaces, keeps Runwire an optional integration,
and updates the supported binding to published Runwire 3.0. No
new event-loop ownership, implicit runtime or required production Runwire
dependency is introduced. This page describes the candidate; it does not
announce a published tag.

Correctness and security
------------------------

- Explicit malformed HTTP allow-list, block-list and private-network policy
  metadata is rejected rather than silently converted to an absent policy.
  Existing redirects continue to revalidate destinations and DNS policy.
- Spool destination publication cannot replace a previously published file.
  File movement uses exclusive, no-clobber publication on one filesystem and
  restricted-permission staging on another. Restoring a claimed message also refuses to
  overwrite a newly arrived message with the same original basename.
- Inbound spool reading checks the identity and regular-file type of the
  opened file against the path selected for admission. A symlink substituted
  between discovery and open is not parsed as an email. Message size is
  verified from the opened file descriptor before bounded reading.
- Multi-worker tests exercise independent consumers, crashed claims and
  publication collisions. A crashed processing claim remains on disk for
  explicit operator recovery; this release does not automatically replay
  in-flight messages or offer exactly-once delivery.
- Runwire 3.0 closed coroutine scopes are treated as a host-lifecycle error
  at the next cooperative sleep/readiness operation. The adapter does not
  substitute synchronous I/O or falsely claim to detect closed scopes
  during binding through a non-existent public API. Hosts must keep the
  borrowed scope open for each operation.
- HTTP signed-upload resource ownership, cURL-multi wait/scheduling,
  IMAP response reading, gRPC native metadata, retry, parsing and
  stream-boundary checks have been consolidated without broadening public
  protocol APIs or weakening validation.

Compatibility and limitations
-----------------------------

Applications without Runwire continue to use synchronous transports. A
runtime-only binding remains valid. Blocking DNS, TLS handshakes,
single-request cURL, native gRPC internals and spool filesystem operations
are not converted into asynchronous work. Cross-filesystem recovery and
advisory file-lock guarantees depend on the operating system and on all
consumers following the same spool ownership policy; do not give untrusted
writers access to spool directories.

Validation
----------

The review baseline is the published 2.3 tree; the CI performance comparator
continues to use tag 2.2 for matched sustained-throughput regression evidence.
A fully green pre-hardening safety candidate at commit
``7eb3f84c13669daeafc0e9aa303ffb0d2a695164`` passed 471 tests with
2,501 assertions, PHP 8.4/8.5 normal and lowest dependency lanes, PHPForge
static/formatting/architecture checks, optional Runwire on/off, native gRPC,
Mailpit, docs, isolated production install and sustained HTTP comparison.

That historical candidate measured unbound RPM regressions of -0.46% at c5,
-1.41% at c20 and -0.46% at c50 versus tag 2.2, within the unchanged 2%
acceptance limit. The implementation candidate at
``25b902847b42648589af4bc60ea1d4a7ccd031a5`` subsequently passed
476 tests / 2,531 assertions and the full hosted release matrix
(`run 41314006754 <https://github.com/infocyph/TalkingBytes/actions/runs/41314006754>`_).
Its same-runner unbound RPM differences were +0.64% at c5, -1.91% at c20,
and -1.97% at c50, with no errors or timeouts. Earlier c50 comparisons on
the pre-optimization revision exceeded the 2% limit; redundant HTTP
destination admission was removed while retaining the guard in DNS pinning.
The successful c50 result is near the limit and should not be represented
as a universal throughput gain. A final HEAD check is required after
the release-evidence documentation update. See the maintained
`2.3.1 audit and release plan <https://github.com/infocyph/TalkingBytes/blob/patch/runwire-3/plans/talkingbytes-2.3.1-audit.md>`_
for tracked evidence and limitations. Do not tag or merge before the final
candidate has been certified and manually reviewed.
