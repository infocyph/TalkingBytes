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
- verify no sensitive values in emitted events/log metadata
- verify fake transports and smoke tests stay green

Protocol readiness
------------------

- HTTP: streaming, limits, security guards, pool behavior validated
- gRPC: retry and fake/native boundaries validated
- Webhook: sign/verify/replay/redaction behavior validated
- Email: SMTP/IMAP/POP3/parser/bounce/auth flows validated

Documentation readiness
-----------------------

- README examples are current
- docs/ pages reflect API and module boundaries
- extension requirements/suggestions are consistent with composer metadata
- public documentation links resolve for the release version
- the versioning and compatibility boundary remains accurate

Versioning
----------

- update changelog or release notes
- review the public API snapshot before accepting any breaking change
- compare component benchmarks with the accepted baseline
- tag only after CI, integration, release-guard, and documentation checks pass
