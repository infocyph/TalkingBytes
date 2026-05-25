Performance
===========

HTTP
----

- use streaming download/upload for large payloads
- set max body/download/upload limits
- use concurrent pool for high-latency fan-out

Email
-----

- use streaming message build/send paths for large attachments
- use parser limits to bound inbound complexity
- use lazy IMAP part fetch where attachment payload is deferred

gRPC
----

- set deadlines per call
- enable retry only for idempotent/transient failures

General
-------

- keep retries bounded with backoff and jitter
- emit events/metrics for timing and failure analysis
- use fake transports for local and CI determinism
