Performance
===========

HTTP
----

- use streaming download/upload for large payloads
- set max body/download/upload limits
- use the rolling concurrent pool for high-latency fan-out
- scope mutable cookie/resilience collaborators deliberately in persistent workers

Email
-----

- use streaming message build/send paths for large attachments
- use parser limits to bound inbound complexity
- use lazy IMAP part fetch where attachment payload is deferred
- keep mailbox/socket connection state execution- or worker-owned

gRPC
----

- set deadlines per call
- enable retry only for idempotent/transient failures
- scope generated/native stubs and channels according to the host lifecycle

Persistent runtimes
-------------------

TalkingBytes does not use a process-global runtime registry for protocol state.
Long-lived hosts should reuse immutable graphs and explicitly scope mutable
collaborators such as cookie jars, resilience objects, mailbox sessions, native
gRPC clients, fakes, and spies.

Use ``CancellationSignal`` to adapt host stop/heartbeat/release state into
retry, wait, stream, pool, mailbox, sendmail, and inbound gRPC boundaries.
Elapsed durations and internal deadlines use monotonic time; wall-clock time is
reserved for protocol semantics that require a real timestamp.

Optional capabilities remain cold until selected. Do not preload native gRPC,
IMAP, POSIX, PCNTL, or Sodium merely because another protocol is active.

General
-------

- keep retries bounded with backoff and jitter
- emit events/metrics for timing and failure analysis
- use fake transports for local and CI determinism
- keep CPU microbenchmarks separate from network, disk, and child-process I/O

Benchmarks
----------

Run the repeatable component benchmark suite with ``composer ic:benchmark``.
Record the PHP version, extensions, OPcache state, operating system, hardware,
and peak memory where meaningful before comparing runs.

The native suite includes:

- HTTP request/auth preparation, immutable and resolved factory construction,
  fake/cookie-enabled sends, and middleware/resilience composition
- webhook signing, parsing, verification, replay claim, and duplicate rejection
- gRPC request construction, unary callback dispatch, and inbound dispatch
- email preparation, raw build/stream build, parser, null send, fake send, and
  the existing 1/10/25 MB streaming payload cases
- resilience primitive overhead

The rolling cURL-multi scheduler is also covered by deterministic local-server
tests that prove a freed concurrency slot is refilled before a slow peer
completes. Treat end-to-end network/process benchmarks separately from CPU
microbenchmarks.

Soak evidence
-------------

``tests/RuntimeSoakTest.php`` repeatedly creates and releases protocol graphs
and uses ``WeakReference`` plus garbage collection to detect accidental
process-global retention. It also verifies that new mutable fake/replay graphs
start with clean state. This is intentionally machine-independent; do not replace
it with fragile absolute memory or timing thresholds.

These benchmarks and soak tests do not establish production application RPM.
Measure sustained successful RPM separately on the production-equivalent host
application and include concurrency, failures, timeouts, latency percentiles,
and memory in that result. The integrating host application owns attribution
between its bridge/framework overhead and TalkingBytes protocol work.
