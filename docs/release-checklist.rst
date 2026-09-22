Release Checklist
=================

Pre-release gates
-----------------

- run ``composer validate --strict`` with zero failures
- run ``composer ic:ci`` with zero failures
- run ``composer ic:release:guard`` with zero failures
- build documentation with warnings treated as errors:

  .. code-block:: bash

     sphinx-build -W --keep-going -b html docs build/docs

- verify the supported PHP and dependency-version matrix is green in CI
- verify the Mailpit integration job is green
- verify the optional-capability coldness job is green with gRPC, IMAP, POSIX,
  PCNTL, and Sodium disabled
- verify no primary runtime path depends on ``CommunicationEventBus``
- verify temporary process-global error handlers are restored and do not span
  user callbacks, event dispatch, Fiber suspension, or long-lived waits
- verify elapsed/deadline paths use monotonic time where protocol wall time is
  not required
- verify cancellation cleanup for retry, stream, pool, mailbox, and sendmail
  paths
- verify no sensitive values in emitted events/log metadata
- verify secret/PII sentinel tests remain green
- verify fake transports and smoke tests stay green
- verify repeated-run soak checks show no unbounded handle/state growth

Protocol readiness
------------------

- HTTP: streaming, limits, security guards, rolling pool scheduling, cancellation
  cleanup, and result ordering validated
- gRPC: retry, generated/native adapter determinism, inbound accepted-exchange
  boundary, streaming cleanup, and wire-error redaction validated
- Webhook: sign/verify/replay/redaction behavior validated; replay claims are
  atomic and fail closed by contract
- Email: SMTP/IMAP/POP3/parser/bounce/auth flows validated; sendmail timeout and
  portable/POSIX process cleanup validated
- mutable cookie, resilience, mailbox, native-client, fake, and spy lifetimes
  remain explicit

Documentation readiness
-----------------------

- README examples are current
- docs/ pages reflect API, lifetime, cancellation, and ownership boundaries
- extension requirements/suggestions are consistent with composer metadata
- optional capability behavior matches the minimal-extension CI gate
- public documentation links resolve for the release version
- the versioning and compatibility boundary remains accurate

Versioning
----------

- update changelog or release notes
- review the public API snapshot before accepting any breaking change
- compare native component benchmarks with the accepted baseline
- record PHP version, extension set, OPcache state, operating system, hardware,
  peak memory where meaningful, and benchmark class/methods used
- freeze the exact release head before the final supported matrix
- tag only the exact commit whose CI, integration, release-guard, benchmark,
  soak, and documentation gates passed
