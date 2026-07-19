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

Benchmarks
----------

Run the repeatable component benchmark suite with ``composer ic:benchmark``.
Record the PHP version, extensions, OPcache state, operating system, and
hardware when comparing results. These microbenchmarks cover repeated HTTP
request preparation, email building/parsing, and webhook verification; they do
not establish production application RPM. Measure end-to-end sustained
successful RPM separately on the production-equivalent host application and
include concurrency, failures, timeouts, latency percentiles, and memory in the
result.
