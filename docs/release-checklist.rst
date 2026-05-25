Release Checklist
=================

Pre-release gates
-----------------

- run ``composer ic:ci`` with zero failures
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

Versioning
----------

- update changelog/release notes (if maintained)
- tag only after CI and docs checks pass
