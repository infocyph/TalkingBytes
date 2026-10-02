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

Runwire 2.3 evidence
--------------------

The HTTP benchmark suite records three additional composition costs:
``benchUnboundFactoryConstruction``, ``benchRunwireBoundFactoryConstruction``
and ``benchRunwireBoundResolvedFactoryConstruction``. Keep these results beside
the existing resolved-factory and transport benchmarks when reviewing a 2.3
candidate.

The Runwire integration tests use real coroutine scheduling around local
HTTP/SMTP/IMAP/POP3/process fixtures to verify that a host peer continues to
progress during every wait documented as cooperative. Those tests are fairness
and ownership evidence, not a substitute for sustained throughput evidence.

Sustained HTTP release gate
---------------------------

The 2.3 release workflow runs a same-runner sustained local HTTP fan-out
comparison. It checks out tag ``2.2`` and the candidate head separately and
drives the same delayed local HTTP fixture at concurrency ``5``, ``20``, and
``50``. Each level uses a one-second warm-up followed by three measured
five-second steady-state trials. The gate records successful RPM, p50/p95/p99
**batch** latency, errors, timeouts, CPU, peak memory, resource count, and
resource delta. The JSON reports are retained as the
``sustained-http-performance`` workflow artifact.

Implementation-acceptance run ``37043405571`` compared 2.2 revision
``ed7873c033498132a169b792e63f601aef98d1ca`` with 2.3 revision
``cfb59a1995fb7e84e5cc5726f689b1f5021b9765``:

- concurrency 5: 2.2 ``27,059.92 RPM``; 2.3 unbound ``27,099.24 RPM``
  (``+0.15%``); Runwire-bound ``26,772.98 RPM`` (``-1.20%`` versus 2.3
  unbound)
- concurrency 20: 2.2 ``91,990.37 RPM``; 2.3 unbound ``92,489.02 RPM``
  (``+0.54%``); Runwire-bound ``91,636.85 RPM`` (``-0.92%`` versus 2.3
  unbound)
- concurrency 50: 2.2 ``179,151.79 RPM``; 2.3 unbound ``176,790.43 RPM``
  (``-1.32%``); Runwire-bound ``177,472.56 RPM`` (``+0.39%`` versus 2.3
  unbound)
- all warm-up and measured windows recorded zero errors and zero timeouts
- all three candidate levels recorded resource delta ``0`` and max resource
  count ``8``
- candidate unbound max CPU across the three levels was ``40.72%`` and peak
  memory was ``8 MiB``; Runwire-bound max CPU was ``40.93%`` and peak memory
  was ``10 MiB``

The 2% release regression threshold is enforced independently at every
concurrency level for the directly comparable 2.2 unbound → 2.3 unbound path.
All three levels passed. Runwire-bound throughput is characterized separately
because 2.2 has no equivalent Runwire mode; those measurements are not
misrepresented as a before/after Runwire threshold.

Production RPM is host-owned
----------------------------

The repository sustained test is a representative library-owned local fan-out
measurement, not a universal application capacity claim. TalkingBytes cannot
publish one meaningful production RPM or latency percentile for every framework,
downstream service, network, TLS/DNS environment, worker count, and host
scheduler. Integrating applications that require deployment capacity numbers
should additionally record successful RPM, p50/p95/p99, failures, timeouts, CPU,
memory, queue growth, active handles, downstream concurrency, and the exact
runtime/extension/OPcache/hardware revisions on their production-equivalent host.
Do not count failed or timed-out work as throughput.

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
