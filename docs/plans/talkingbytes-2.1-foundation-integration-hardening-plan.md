# TalkingBytes 2.1 — Foundation 3 Integration Hardening & Runtime Ownership Plan

## Status

Recommended target release: **TalkingBytes 2.1.x**

Baseline:

- working branch: talkingbytes-2.1/foundation-integration-hardening
- implementation baseline branch: main
- released baseline: 2.0.0
- baseline commit: 86d0e9dde8124ddeacea8ba7f81911af584b879b
- current implementation head through Batch 8: 45c7dd11d1c77c3e4ff1b68ab4a1999ed5f6761f
- primary consumer: Foundation 3 runtime plan point 26.9
- plan state: **ACTIVE — BATCHES 1–8 GREEN / BATCH 9 NEXT**

Batch progress:

- [x] Batch 1 — runtime-state, clock and cancellation foundation
- [x] Batch 2 — gRPC security and host boundary
- [x] Batch 3 — email runtime and sendmail process hardening
- [x] Batch 4 — native protocol composition builders
- [x] Batch 5 — webhook replay acceptance
- [x] Batch 6 — HTTP rolling multi scheduler
- [x] Batch 7 — gRPC generated adapter determinism
- [x] Batch 8 — observability and data minimization
- [ ] Batch 9 — optional cold graphs, docs and benchmarks
- [ ] Batch 10 — exact-head release gate

TalkingBytes 2.0 already established the intended protocol architecture. The 2.1 release should harden that architecture for persistent workers, Fibers, framework integration and high-throughput use while moving protocol composition out of Foundation where it currently leaks upward.

The default release remains 2.1 because the required work can be implemented additively. Promote the release to the next major only if implementation proves that a public removal, incompatible constructor/signature change, or incompatible configuration semantic is genuinely required. Do not create a major release merely to permit cleanup that can remain additive.

---

## 1. Release Policy

### 1.1 Compatibility

Keep 2.1 additive and minor-release compatible wherever practical.

Priority order:

1. correctness
2. security
3. runtime isolation
4. protocol ownership
5. performance
6. scalability
7. API clarity
8. compatibility

If a correctness or security defect cannot be fixed compatibly, make the smallest necessary correction, document it, and use the major-version decision gate in Section 18.

### 1.2 Runtime floor

Keep PHP >= 8.4.

Keep infocyph/phpforge dev-main@dev in require-dev.

### 1.3 Dependency policy

TalkingBytes must remain framework-agnostic and lightweight.

Do not add Foundation, InterMix, CacheLayer, DBLayer, Omnibus, Pathwise or another Infocyph runtime library merely to simplify host integration.

Optional integrations remain behind native contracts and adapters.

### 1.4 Extension policy

Do not make pcntl or posix required extensions.

- posix may be used opportunistically for safer Unix child-process group termination where available.
- pcntl must not be used implicitly by normal HTTP, email, webhook or gRPC object graphs.
- do not add fork-based protocol concurrency.
- do not install process-global signal handlers from ordinary protocol clients/transports.
- if a standalone CLI signal-to-cancellation adapter is eventually added, it must be explicit opt-in, restore previous handlers, remain optional and be independently tested.
- Foundation keeps ownership of its worker/supervisor signal lifecycle.

---

## 2. Ownership Boundary

### TalkingBytes owns

- outbound HTTP request preparation and transport;
- redirect processing, HTTP streaming, concurrent request mechanics, retry and transport security;
- HTTP authentication, signing, idempotency and cookie mechanics;
- protocol-native client/factory composition from already-resolved values;
- inbound and outbound email protocol mechanics;
- SMTP, sendmail, PHP mail, spool and logging transports;
- IMAP and POP3 mailbox behavior;
- MIME parsing, transfer decoding, charset decoding and attachment extraction;
- DKIM, authentication-result parsing and bounce classification;
- webhook signing, verification, timestamp validation, retry semantics and replay-store contract;
- gRPC request/response models;
- gRPC metadata, deadlines, status mapping, retry and stream mechanics;
- generated/native gRPC adapters;
- host-controllable inbound gRPC exchange adaptation;
- protocol-level cancellation checks where an operation can wait, retry, stream or poll;
- protocol fakes, assertion helpers, events and native benchmarks.

### Foundation owns

- named application profile lookup and default profile selection;
- capability selection and dependency activation;
- DI lifetime selection;
- application secret resolution and production policy;
- application path resolution;
- application-level configuration source mapping;
- CacheLayer-backed webhook replay-store implementation;
- worker heartbeat, stop and release-generation lifecycle;
- process supervision for Foundation console/scheduler/application commands;
- application service/handler lookup and DI mapping;
- application logging/audit policy;
- application notification/template mapping;
- direct-versus-TalkingBytes bridge benchmarks.

### Explicit non-ownership

TalkingBytes must not become:

- a Foundation-specific package;
- an HTTP application framework/server;
- a cache/database abstraction;
- a queue or worker supervisor;
- a generic application process manager;
- a home-grown gRPC wire stack;
- a service container;
- a named application-profile repository.

### Foundation code that should remain in Foundation

Do not move these merely to make Foundation smaller:

- CacheLayerWebhookReplayStore;
- production TLS policy and deployment-specific security policy;
- change-me/default-secret rejection;
- named profile lookup;
- application path expansion;
- DI service-to-gRPC-handler resolution;
- auth notification mapping/templates;
- notification recipient routing;
- Foundation ProcessRunner used by console, module and scheduler execution.

TalkingBytes should only absorb the lower-level protocol composition currently duplicated around those policies.

---

## 3. Verified Baseline Findings

### 3.1 Process-global event state remains in runtime paths

CommunicationEventBus stores a static dispatcher.

Production runtime paths still call it directly, including:

- Email/Receiver/SpoolEmailReceiver;
- Email/Mailbox/SocketMailboxRuntime;
- Email/Parser/BounceParser.

The static bus is already documented as compatibility-only, but these runtime paths still make global state part of normal execution.

### 3.2 HTTP graph is immutable, selected collaborators are mutable

HttpClient is fluent/immutable, but these collaborators intentionally retain state:

- CookieJar;
- CircuitBreaker;
- RateLimiter;
- fake/spy transports.

Host lifetime rules must therefore be explicit and tested.

### 3.3 Webhook replay abstraction is correctly host-neutral

WebhookReplayStore::claim(namespace, deliveryId, ttlSeconds) expresses the correct lower-layer requirement:

- atomic first claim;
- bounded TTL;
- duplicate detection.

Foundation should keep its CacheLayer implementation. TalkingBytes should harden the contract and tests, not acquire a CacheLayer dependency.

### 3.4 Inbound gRPC currently stops at dispatch

GrpcInboundDispatcher maps normalized inbound requests to handlers but does not provide a host-facing accepted-call/source/exchange boundary that a Foundation worker can drive one call at a time.

### 3.5 Inbound gRPC currently leaks implementation detail into response metadata

When an inbound handler throws, GrpcInboundDispatcher returns a GrpcInboundResponse containing the exception class in response metadata.

That metadata can cross the protocol boundary. Internal exception classes must not be returned to remote callers by default.

The exception class may be retained in local observability where policy permits, but not in the wire response.

### 3.6 Observability redaction is not uniformly strict

Some protocol events still include raw result errors or exception messages.

Email/spool events can also expose operational paths or message subjects. These are not authentication secrets, but they may be sensitive application/PII data and should not be default observability fields when a stable identifier/category is sufficient.

### 3.7 Optional capability coldness needs release evidence

Native gRPC and multiple mail-related capabilities are optional by design.

Unrelated protocol use must not eagerly require or initialize optional capabilities.

### 3.8 Timing is only partially monotonic

Core/Support/Clock already supports a monotonic source using hrtime, and gRPC retry uses it.

Other runtime paths still use microtime(true) or time() for durations/deadlines, including:

- HTTP transport/pool durations;
- webhook delivery duration;
- gRPC client/transport/inbound event durations;
- SMTP command timing;
- sendmail timeout handling;
- IMAP/POP3 deadlines;
- mailbox watch loops;
- spool receive events;
- Emailer events.

Elapsed time and deadlines should use the monotonic clock. Wall time should remain only where protocol semantics require real timestamps, such as webhook signature timestamps.

### 3.9 Waiting and cancellation are inconsistent

TalkingBytes already has Sleeper and mailbox watch callbacks such as shouldStop, but waiting behavior is fragmented:

- retry paths sleep through Sleeper;
- sendmail uses raw usleep loops;
- POP3 watch uses time plus raw usleep;
- IMAP watch uses stream_select plus raw usleep fallback;
- generated/native gRPC stream loops do not expose a uniform cancellation check;
- HTTP multi does not accept a cooperative cancellation signal.

Persistent hosts need one small lower-layer cancellation contract so Foundation can adapt its heartbeat/stop/release policy without TalkingBytes depending on Foundation.

### 3.10 Sendmail process supervision is weaker than Foundation process execution

SendmailTransport owns a private proc_open loop and terminates the direct child with proc_terminate.

Foundation has stronger generic process handling with timeout/cancellation and optional POSIX process-group termination. The full Foundation ProcessRunner must not move into TalkingBytes because Foundation uses it for console/scheduler/application process execution.

TalkingBytes should instead own a narrow sendmail child-process supervisor with:

- array command/no shell;
- bounded stdout/stderr capture;
- monotonic timeout;
- cooperative cancellation;
- graceful then forced termination;
- optional POSIX process-group termination when safely available;
- portable direct-child fallback.

### 3.11 Foundation duplicates TalkingBytes protocol composition

Foundation CommunicationProfiles currently composes TalkingBytes behavior for:

- HTTP auth;
- CookieJar;
- HTTP retry;
- RateLimiter;
- CircuitBreaker;
- idempotency;
- gRPC retry;
- generated/native gRPC client selection;
- webhook signing and retry.

Foundation EmailProfiles currently composes:

- transport-driver selection;
- fallbacks;
- retry;
- rate limiting;
- DKIM;
- sender transport config.

Foundation NotificationGraphFactory also reconstructs EmailLimits from arrays.

These are protocol-native composition concerns once paths/secrets/default profile names have already been resolved.

### 3.12 Generated gRPC stub adaptation uses exception-driven signature probing

GeneratedStubGrpcInvoker opens streaming calls by attempting one invocation signature and catching ArgumentCountError or TypeError before trying another.

Catching TypeError around the method invocation can accidentally treat a real TypeError from inside a user/generated stub as an invocation-shape mismatch and may duplicate side effects.

Resolve/validate the call shape deterministically instead of probing by executing and catching broad TypeError.

### 3.13 HTTP multi concurrency is chunked, not a rolling window

CurlMultiTransport currently array-chunks requests by max concurrency, waits for the full chunk to complete, then schedules the next chunk.

This causes avoidable head-of-line blocking when one slow request holds back scheduling even though another slot has become free.

A rolling-window scheduler can improve throughput and latency without adding threads or fork-based concurrency.

### 3.14 Raw global error-handler usage requires an isolation audit

Several paths temporarily call set_error_handler for warning capture/suppression.

Most restore it in finally and do not deliberately suspend a Fiber while installed, so this is not automatically a defect. Still, the release should prove that no code path can yield/call arbitrary user code while a temporary process-global handler is installed.

Prefer expression-local/native error handling where practical.

---

## 4. Workstream 1 — P0 Runtime Global-State Isolation

### Goal

Normal TalkingBytes object graphs must not depend on process-global mutable state.

### Tasks

- [x] Propagate optional EventDispatcher dependencies through email sender/receiver/mailbox/parser factories where events are emitted.
- [x] Convert SpoolEmailReceiver lifecycle events to injected dispatch.
- [x] Convert mailbox command events away from direct CommunicationEventBus use.
- [x] Convert BounceParser event emission away from direct CommunicationEventBus use.
- [x] Audit every production src reference to CommunicationEventBus.
- [x] Keep CommunicationEventBus only as a compatibility facade.
- [x] Ensure new runtime code never requires the static bus.
- [x] Keep dispatch best-effort: listener failures must not alter protocol results or cleanup.
- [ ] Audit temporary set_error_handler regions.
- [ ] Ensure no temporary global error handler spans arbitrary user callbacks, Fiber suspension, event dispatch or long-lived loops.
- [x] Add sequential persistent-runtime tests proving event listeners and temporary runtime state do not leak.
- [x] Add Fiber-interleaving tests for relevant stateless/object-scoped paths.
- [x] Update events documentation to make injection authoritative.

### Acceptance

A normal graph created through public constructors/factories must work correctly with CommunicationEventBus untouched.

---

## 5. Workstream 2 — P0 Monotonic Time, Cancellation and Interruptible Waiting

### Goal

Long-running/retrying operations become host-controllable without TalkingBytes owning the host lifecycle.

### Direction

Introduce the smallest useful cancellation abstraction. Exact naming may change.

Conceptually:

- CancellationSignal::isRequested(): bool;
- a never-cancelled implementation;
- optional adapter from a callable;
- no dependency on Foundation;
- no global registry.

Do not create a general task framework.

### Tasks

- [ ] Standardize elapsed durations and internal deadlines on Core/Support/Clock::monotonic().
- [ ] Keep Clock::timestamp()/wall time only for protocol timestamps that require real time.
- [x] Extend waiting support so retry/backoff sleeps can be interrupted in bounded slices when a cancellation signal is supplied.
- [x] Keep the current simple Sleeper path cheap when no cancellation is supplied.
- [x] Allow RetryExecutor to stop before the next attempt when cancelled.
- [x] Allow HTTP retry and gRPC retry to stop before sleeping/retrying when cancelled.
- [ ] Allow WebhookSender retry to stop cooperatively.
- [x] Allow mailbox watch loops to consume the same cancellation abstraction while retaining callable compatibility where practical.
- [x] Allow generated/native gRPC streaming loops to check cancellation between messages/writes/reads where the native API permits.
- [x] Allow the inbound gRPC accepted-call bridge to stop before accepting the next exchange.
- [x] Allow CurlMultiTransport to stop scheduling and terminate/close active work safely when host cancellation is requested, if libcurl semantics permit deterministic cleanup.
- [ ] Add deterministic fake-clock/fake-sleeper/cancellation tests.
- [x] Verify cancellation never skips required resource cleanup.

### Foundation handoff

Foundation adapts heartbeat loss, stop token and release-generation replacement into the TalkingBytes cancellation boundary. TalkingBytes does not know those Foundation concepts.

---

## 6. Workstream 3 — P0 Mutable-State Lifetime Contracts

### Required classifications

| Component | State model | Host expectation |
| --- | --- | --- |
| HttpClientConfig | immutable configuration | reusable |
| HttpClient without mutable collaborators | immutable graph | reusable when policy allows |
| CookieJar | mutable session state | execution/session scoped |
| CircuitBreaker | mutable resilience state | explicit shared/profile scope only |
| RateLimiter | mutable token state | explicit shared/profile scope only |
| WebhookVerifier | immutable secret/policy graph | reusable if secret lifecycle permits |
| WebhookReceiver | immutable graph around replay store | replay-store lifetime dependent |
| GrpcClient | immutable graph over invoker | invoker lifetime dependent |
| GrpcInboundDispatcher | immutable handler graph | reusable if handlers are safe |
| Emailer | immutable graph over transport | transport lifetime dependent |
| SMTP transport | per-send connection today | reusable graph if collaborators are safe |
| mailbox/socket transports | connection/session state | execution/worker owned |
| generated gRPC/native invokers | native channel/stub lifetime dependent | host/profile scoped deliberately |
| fakes/spies | mutable test state | test scoped |

### Tasks

- [ ] Publish the matrix in architecture/runtime docs.
- [ ] Prove fluent operations do not mutate previous instances.
- [ ] Prove CookieJar isolation.
- [ ] Prove CircuitBreaker isolation.
- [ ] Prove RateLimiter isolation.
- [x] Prove mailbox connections are not shared accidentally across scoped graphs.
- [ ] Document native gRPC stub/channel lifetime expectations.
- [ ] Add sequential/Fiber tests around mutable collaborators.
- [x] Do not introduce global resilience or native-client registries.
- [ ] Ensure fake/spy state has deterministic new-instance/reset behavior.

---

## 7. Workstream 4 — P0 Webhook Replay Hardening

### Goal

Replay protection remains protocol-owned and storage-provider-neutral.

### Tasks

- [x] Keep WebhookReplayStore minimal.
- [x] Document that production claim must be atomic across competing processes.
- [x] Document backend errors as fail-closed.
- [x] Add a contention contract test where only one contender wins.
- [x] Add a throwing-store test proving replay protection is not bypassed.
- [x] Preserve strict positive TTL validation.
- [x] Preserve bounded namespace/delivery-ID validation.
- [x] Preserve signature/timestamp verification before replay claim.
- [x] Preserve replay claim before a verified event is returned.
- [x] Mark InMemoryWebhookReplayStore clearly as single-process/test/local-use unless its guarantees are sufficient for the documented deployment.
- [x] Ensure replay observability never exposes raw secret/signature/body.
- [x] Do not add CacheLayer.

### Foundation handoff

Foundation keeps CacheLayerWebhookReplayStore and its Foundation security cache-key domain.

---

## 8. Workstream 5 — P0 Host-Controlled Inbound gRPC Runtime Bridge

### Goal

Foundation or another host can run inbound gRPC through its own lifecycle without recreating TalkingBytes protocol adaptation.

### Target flow

native/server runtime
→ TalkingBytes inbound source/adapter
→ accepted exchange
→ GrpcInboundRequest
→ GrpcInboundDispatcher
→ GrpcInboundResponse
→ TalkingBytes exchange completion

### Required characteristics

- [x] Add a small contract for accepting/obtaining one inbound gRPC exchange.
- [x] Accepted exchange exposes normalized GrpcInboundRequest.
- [x] TalkingBytes maps GrpcInboundResponse/status/metadata back to the native exchange.
- [x] Provide a one-cycle or otherwise host-controllable execution API.
- [ ] Accept cancellation between calls and, where supported, during streams.
- [x] Do not hide an uncontrolled infinite process loop.
- [x] Preserve method normalization, metadata, deadline and status mapping.
- [x] Add fake inbound source/exchange utilities.
- [x] Do not add socket/process supervision.
- [x] Do not require Foundation or Omnibus.
- [ ] Keep ext-grpc and grpc/grpc cold until selected.
- [ ] Document exact inbound streaming modes actually implemented.
- [ ] Keep inbound streaming incremental and bounded.

### Security correction

- [x] Remove handler exception class from GrpcInboundResponse wire metadata.
- [x] Return stable INTERNAL status/message only.
- [x] Keep richer exception classification only in local events/logging when safe.
- [x] Add a test proving remote responses do not reveal exception class, file path, trace or raw exception message.

---

## 9. Workstream 6 — P0/P1 Persistent-Runtime Email and Sendmail Process Hardening

### Email runtime tasks

- [x] Propagate injected EventDispatcher objects through EmailSenderFactory, EmailReceiverFactory and EmailMailboxFactory.
- [x] Keep Emailer transport composition native to TalkingBytes.
- [x] Keep SMTP/sendmail/mail/spool behavior native.
- [x] Keep IMAP/POP3 behavior native.
- [x] Keep MIME/parsing/DKIM/bounce behavior native.
- [x] Define mailbox connection ownership and deterministic close/logout behavior.
- [x] Ensure failed sessions cannot poison newly constructed instances.
- [ ] Preserve bounded line/message/attachment/parser limits.
- [ ] Preserve spool locking, quarantine and safe move semantics.
- [x] Replace wall-clock logical deadlines with monotonic clock.
- [x] Replace raw watch-loop sleeps with injectable waiting where useful.
- [x] Keep IMAP IDLE cancellation responsive.
- [x] Keep POP3 polling cancellation responsive.
- [x] Add persistent-worker and cancellation tests.

### Sendmail subprocess tasks

- [x] Keep command execution as an argument array and bypass the shell.
- [x] Extract the private process loop into a narrow internal sendmail child-process helper if that reduces duplication/complexity.
- [x] Use monotonic timeout.
- [x] Add cooperative cancellation.
- [x] Keep stdout/stderr capture bounded.
- [x] Terminate gracefully, wait a bounded grace period, then force termination.
- [x] When posix_setpgid/posix_getpgid/posix_kill are available and safe, place the child in its own process group and terminate the group so descendants are not orphaned.
- [x] Fall back to direct proc_terminate when POSIX group control is unavailable.
- [x] Do not require ext-posix.
- [x] Do not require ext-pcntl.
- [x] Do not import Foundation ProcessRunner or make TalkingBytes a generic process package.
- [ ] Add tests for timeout, cancellation, forced termination and cleanup.
- [ ] Add optional Unix process-group coverage where CI supports it.
- [ ] Verify Windows/non-POSIX fallback behavior remains valid.

### pcntl policy

- [x] Do not register SIGINT/SIGTERM handlers inside SendmailTransport, SMTP, HTTP, webhook or gRPC normal paths.
- [x] Foundation continues translating its worker signals into cancellation.
- [ ] Consider an explicit standalone PcntlSignalCancellation adapter only if a non-Foundation CLI use case justifies it.
- [ ] If such an adapter is added, it must restore previous handlers and never become a default dependency path.

---

## 10. Workstream 7 — P1 Native Composition Builders to Shrink Foundation

### Goal

Foundation should select named profiles and resolve application values. TalkingBytes should turn resolved protocol configuration into protocol objects.

Do not introduce Foundation-specific configuration names or a large profile framework.

Prefer extending existing factories/facades before adding many new abstractions.

### HTTP composition

Move the mechanics currently in Foundation CommunicationProfiles::decorateHttp into a TalkingBytes-native builder/factory:

- [x] auth driver composition;
- [x] CookieJar opt-in;
- [x] retry policy composition;
- [x] RateLimiter composition;
- [x] CircuitBreaker composition;
- [x] idempotency middleware composition.

Foundation should still:

- choose the named HTTP profile;
- resolve secrets;
- enforce production TLS policy;
- decide DI lifetime.

### gRPC composition

- [x] Add a direct TalkingBytes convenience path for generated stubs so Foundation does not construct GeneratedStubGrpcInvoker itself unless it needs customization.
- [x] Centralize native/generated/streaming client composition in TalkingBytes.
- [x] Centralize gRPC retry-profile application in TalkingBytes.
- [x] Allow EventDispatcher injection through usingNative/usingNativeStreaming/generated-stub paths.
- [x] Keep service/handler lookup in Foundation.

### Webhook composition

- [x] Keep signing, verifier/receiver creation and retry-profile mechanics in TalkingBytes.
- [x] Allow a resolved outbound/inbound config array or small typed config to be applied without Foundation recreating protocol rules.
- [x] Keep secret source resolution and production-secret policy in Foundation.
- [x] Keep replay-store implementation in Foundation.

### Email composition

Expand native email factory capability so Foundation no longer has to own protocol transport/decorator mechanics:

- [x] transport driver creation from resolved transport config;
- [x] fallback transport composition;
- [x] retry policy composition;
- [x] rate-limit composition;
- [x] DKIM config/application after path/secret resolution;
- [x] parser-limit parsing.

Specific easy win:

- [x] add EmailLimits::fromArray() using TalkingBytes-native strict config parsing so Foundation NotificationGraphFactory does not duplicate EmailLimits construction.

Foundation should still:

- choose named sender/transport/mailbox/receiver profiles;
- resolve relative application paths;
- resolve private keys/secrets from application configuration;
- apply default From policy;
- own notification/template routing.

### Acceptance

After the Foundation follow-up:

- CommunicationProfiles should mostly perform profile lookup, host policy and delegation.
- EmailProfiles should mostly perform profile lookup/path resolution and delegation.
- no protocol retry/auth/cookie/DKIM/fallback algorithm should be recreated in Foundation.

---

## 11. Workstream 8 — P1 HTTP Concurrent Scheduler and Runtime Control

### Goal

Improve throughput without threads, forks or a new async framework.

### Tasks

- [x] Replace array_chunk batch scheduling with a rolling cURL multi window up to maxConcurrency.
- [x] As soon as one handle completes, schedule the next pending request.
- [x] Preserve result ordering by original keys.
- [x] Preserve bounded concurrency.
- [x] Preserve cleanup on every failure/listener/cancellation path.
- [x] Preserve current truthful stopSchedulingOnFailure semantics.
- [x] When a failure is observed and stop-scheduling is enabled, stop adding new requests immediately.
- [x] Do not claim active-request fail-fast cancellation unless it is actually implemented.
- [x] If cancellation is supplied, close/remove active handles safely and return deterministic cancelled results/metadata.
- [x] Keep manual redirect security behavior; do not re-enable unsafe automatic redirect handling in CurlMultiTransport.
- [x] Move pool durations to monotonic Clock.
- [ ] Benchmark chunked 2.0 behavior versus rolling-window 2.1 behavior with mixed fast/slow fake/local endpoints.
- [ ] Track allocation/handle cleanup under repeated runs.

### Non-goal

Do not add pcntl_fork, pthreads, parallel, ReactPHP or Amp merely for this scheduler.

---

## 12. Workstream 9 — P1 gRPC Native Adapter Determinism and Streaming Control

### Tasks

- [x] Remove exception-driven TypeError probing for generated streaming call shape.
- [x] Resolve the supported generated-stub call shape before executing the real call.
- [x] Prefer explicit adapter metadata/callable strategy or bounded reflection cached at adapter construction.
- [x] Never retry an invocation merely because a TypeError was thrown from inside the invoked method.
- [x] Validate method maps early.
- [x] Keep generated/native package capability checks cold.
- [x] Add cancellation checks between outbound stream writes and inbound reads where possible.
- [x] Preserve incremental streaming; never accumulate full streams.
- [x] Ensure callback exceptions close/finalize native call resources deterministically.
- [x] Add tests proving no duplicate side effect occurs during call-shape resolution.
- [x] Add tests for cancellation, callback failure and final status/trailer handling.

---

## 13. Workstream 10 — P1 Observability, Redaction and Data-Minimization

### Goal

Default events/log context must not expose secrets or unnecessary payload/PII.

### Tasks

- [x] Never emit raw Authorization credentials.
- [x] Never emit raw bearer/API tokens.
- [x] Never emit cookie values.
- [x] Never emit proxy credentials.
- [x] Never emit webhook secrets/signatures/bodies.
- [x] Never emit SMTP/mailbox passwords or raw auth commands.
- [x] Avoid raw gRPC metadata values unless explicitly classified safe.
- [x] Do not copy raw exception messages blindly into protocol events.
- [x] Prefer stable failure category, exception class where locally appropriate, protocol status/code and bounded sanitized diagnostics.
- [x] Remove exception class from remote gRPC response metadata.
- [x] Review SMTP transcript capture and document it as explicit diagnostic data with clear redaction guarantees.
- [x] Remove or gate spool absolute paths and email subjects from default events when they are not required.
- [x] Keep caller-facing CommunicationResult diagnostics useful; local observability may intentionally be stricter.
- [x] Add sentinel-secret and sentinel-PII tests across HTTP, webhook, gRPC, email and mailbox event payloads.
- [x] Keep hot-path redaction overhead bounded.

---

## 14. Workstream 11 — P1 Optional Capability and Extension Coldness

### Acceptance matrix

- [ ] HTTP works without ext-grpc, grpc/grpc, ext-posix and ext-pcntl.
- [ ] Webhook works without native gRPC packages.
- [ ] Basic outbound email works without IMAP-specific optional extensions.
- [ ] SMTP works without ext-posix/ext-pcntl.
- [x] Sendmail works with portable proc_* fallback when ext-posix is absent.
- [x] POSIX process-group hardening activates only when functions are available.
- [ ] IMAP/POP3 optional checks occur only when selected.
- [ ] RSA DKIM does not require Sodium.
- [ ] Ed25519 DKIM fails clearly only when selected and Sodium is unavailable.
- [ ] Native/generated gRPC fails clearly only when selected.
- [ ] Composer suggest metadata matches actual optional behavior.
- [x] Add ext-posix to suggest only if the released implementation actually uses it as an optional sendmail hardening path.
- [x] Do not add ext-pcntl to suggest unless an explicit public pcntl adapter is shipped.
- [x] Documentation matches Composer metadata.
- [ ] Avoid unrelated extension/class probing on protocol hot paths.

---

## 15. Workstream 12 — P1 Native Benchmark and Soak Evidence

TalkingBytes owns native protocol benchmarks.

Foundation owns bridge attribution.

### HTTP

- [ ] immutable client construction;
- [ ] resolved-profile/factory construction;
- [ ] request preparation;
- [ ] fake transport send;
- [ ] cookie-enabled send;
- [ ] retry/rate-limit/circuit overhead;
- [ ] rolling multi scheduler;
- [ ] cancellation cleanup;
- [ ] repeated-run memory/handle growth.

### Webhook

- [ ] signing;
- [ ] verification;
- [ ] verification plus replay claim;
- [ ] duplicate rejection;
- [ ] retry/cancellation overhead.

### gRPC

- [ ] unary dispatch;
- [ ] inbound dispatcher;
- [ ] host accepted-exchange bridge;
- [ ] retry decision;
- [ ] generated/native adapter;
- [ ] streaming without eager materialization;
- [ ] cancellation check overhead.

### Email

- [ ] message preparation;
- [ ] null/fake send;
- [ ] parser;
- [ ] spool receive;
- [ ] deterministic mailbox adapter;
- [ ] sendmail process-control overhead;
- [ ] 1/10/25 MB payload paths already relevant to the existing benchmark suite.

### Rules

- [ ] Do not add Foundation as a benchmark dependency.
- [ ] Separate CPU microbenchmarks from network/disk/process I/O.
- [ ] Record peak memory where meaningful.
- [ ] Add repeated-run soak checks for state/resource growth.
- [ ] Use monotonic timing for benchmark duration measurement.
- [ ] Preserve clear attribution.

---

## 16. Documentation and Release Metadata

- [ ] Update architecture docs with ownership/lifetime/cancellation boundaries.
- [x] Update events docs: injected dispatcher primary; static bus compatibility-only.
- [x] Update HTTP concurrency docs for rolling scheduling and cancellation semantics.
- [ ] Update webhook replay docs with atomic/fail-closed requirements.
- [x] Update gRPC inbound docs for the host-runtime bridge and wire-error data minimization.
- [x] Update gRPC generated/native docs for deterministic adapter behavior.
- [x] Update email docs for persistent-worker connection ownership.
- [x] Update sendmail docs for timeout/cancellation/POSIX optional behavior.
- [x] Update security docs with secret/PII redaction guarantees.
- [ ] Update performance docs with persistent-runtime guidance.
- [x] Update testing docs with isolation, fake cancellation and fake inbound-runtime examples.
- [ ] Update release checklist with static-state, monotonic-time, cancellation, optional-cold and secret-sentinel gates.
- [ ] Keep README examples aligned with released APIs.
- [x] Keep Composer requirements/suggestions synchronized with real runtime behavior.

---

## 17. Likely File Touch Map

This is a planning map, not a requirement to modify every file.

### Core/runtime support

- src/Core/Event/*
- src/Core/Support/Clock.php
- src/Core/Support/Sleeper.php
- src/Core/Support/RetryExecutor.php
- new minimal cancellation support if required

### HTTP

- src/Http/HttpClient.php
- src/Http/HttpClientConfig.php
- optional new native HttpClientFactory or equivalent
- src/Http/Concurrent/CurlMultiTransport.php
- src/Http/Concurrent/RequestPool.php
- src/Http/Transport/CurlTransport.php
- src/Http/Middleware/*
- src/Http/Cookie/CookieJar.php
- src/Resilience/CircuitBreaker.php
- src/Resilience/RateLimiter.php

### Email

- src/Email/Email.php
- src/Email/Emailer.php
- src/Email/EmailSenderFactory.php
- src/Email/EmailReceiverFactory.php
- src/Email/EmailMailboxFactory.php
- src/Email/Config/EmailLimits.php
- src/Email/Config/DkimConfig.php where useful
- src/Email/Transport/SendmailTransport.php
- src/Email/Transport/SmtpTransport.php
- src/Email/Receiver/SpoolEmailReceiver.php
- src/Email/Mailbox/SocketMailboxRuntime.php
- src/Email/Mailbox/ImapSocketTransport.php
- src/Email/Mailbox/Pop3SocketTransport.php
- src/Email/Parser/BounceParser.php
- relevant tests

### Webhook

- src/Webhook/Webhook.php
- src/Webhook/WebhookSender.php
- src/Webhook/WebhookReceiver.php
- src/Webhook/WebhookVerifier.php
- src/Webhook/Contracts/WebhookReplayStore.php
- src/Webhook/Replay/InMemoryWebhookReplayStore.php
- relevant tests/docs

### gRPC

- src/Grpc/GrpcClient.php
- src/Grpc/GrpcInboundDispatcher.php
- src/Grpc/Middleware/RetryMiddleware.php
- src/Grpc/Native/GeneratedStubGrpcInvoker.php
- src/Grpc/Native/*
- src/Grpc/Receiver/*
- src/Grpc/Testing/*
- optional native GrpcClientFactory or equivalent
- relevant tests/docs

### Benchmarks/release

- benchmarks/HttpBench.php
- benchmarks/WebhookBench.php
- benchmarks/GrpcBench.php
- benchmarks/EmailBench.php
- benchmarks/LargeEmailBench.php
- benchmarks/ResilienceBench.php
- docs/performance.rst
- docs/release-checklist.rst
- composer.json

---

## 18. Major/Minor Decision Gate

### Stay on 2.1 when

- new cancellation/time/factory APIs are additive;
- CommunicationEventBus can remain as a compatibility facade;
- existing constructor signatures can gain only optional parameters or factory alternatives;
- generated gRPC correction can preserve current public contracts;
- rolling HTTP scheduling changes internal behavior without invalidating documented guarantees;
- SendmailTransport can be hardened internally;
- Foundation can migrate to new native builders without removing old TalkingBytes entry points.

### Promote to the next major only when implementation proves one of these is required

- removing CommunicationEventBus rather than merely bypassing it;
- making EventDispatcher mandatory in existing public constructors;
- replacing existing public config keys/semantics incompatibly;
- removing/renaming public methods rather than adding better alternatives;
- changing gRPC streaming contracts incompatibly;
- changing Emailer/transport public ownership semantics incompatibly.

### Next-major cleanup candidates, not 2.1 release gates

- remove the static CommunicationEventBus completely;
- collapse superseded compatibility factories/methods after a deprecation period;
- consider a persistent SMTP session/connection-reuse abstraction with strict idle/max-message/reset/fork-safety policy;
- reconsider any public cancellation/config APIs that cannot be made cleanly additive;
- remove legacy configuration aliases if they exist and are no longer valuable.

---

## 19. Execution Order

### Batch 1 — Runtime-state, clock and cancellation foundation ✅

- remove primary static-event dependency;
- add/propagate injected dispatch;
- standardize monotonic timing;
- introduce minimal cooperative cancellation;
- add persistent/Fiber isolation tests.

### Batch 2 — gRPC security and host boundary ✅

- remove exception metadata leakage;
- add accepted-exchange/source boundary;
- add cancellation;
- add fake runtime;
- preserve status/deadline/metadata semantics.

### Batch 3 — Email runtime and sendmail process hardening ✅

- event injection;
- mailbox/session ownership;
- monotonic deadlines;
- cancellation-aware watch behavior;
- sendmail child-process supervision;
- optional POSIX process-group safety.

### Batch 4 — Native protocol composition builders ✅

- EmailLimits::fromArray;
- HTTP resolved-profile builder;
- email transport/decorator builder;
- gRPC native/generated/retry builder;
- webhook resolved-policy composition;
- tests proving Foundation no longer needs to recreate protocol mechanics.

### Batch 5 — Webhook replay acceptance ✅

- atomic/fail-closed contract;
- contention/error tests;
- preserve provider neutrality.

### Batch 6 — HTTP rolling multi scheduler ✅

- rolling window;
- stop-scheduling behavior;
- cancellation/cleanup;
- throughput benchmark.

### Batch 7 — gRPC generated adapter determinism ✅

- remove TypeError execution probing;
- deterministic call-shape resolution;
- streaming cancellation/failure cleanup.

### Batch 8 — Observability and data minimization ✅

- raw failure audit;
- secret/PII sentinels;
- transcript/path/subject policy.

### Batch 9 — Optional cold graphs, docs and benchmarks ⏳

- extension/package absence matrix;
- native benchmark evidence;
- architecture/security/testing/performance docs;
- Composer metadata.

### Batch 10 — Exact-head release gate

Run the full PHPForge and supported PHP/dependency matrix only after the final implementation head is frozen.

---

## 20. Foundation 3 Handoff

After TalkingBytes 2.1 is released:

- [ ] Foundation raises its communication floor to ^2.1 only when the released APIs are consumed.
- [ ] Foundation keeps named application profile lookup.
- [ ] Foundation keeps path/secret resolution and production policy.
- [ ] Foundation keeps DI lifetime selection.
- [ ] Foundation keeps CacheLayerWebhookReplayStore.
- [ ] Foundation keeps gRPC handler service lookup.
- [ ] Foundation keeps ProcessRunner for console/scheduler/application subprocesses.
- [ ] Foundation maps worker heartbeat/stop/release replacement into TalkingBytes cancellation.
- [ ] Foundation removes duplicated HTTP auth/cookie/retry/rate-limit/circuit/idempotency composition when TalkingBytes native composition is available.
- [ ] Foundation removes duplicated gRPC retry/native/generated composition when TalkingBytes owns it.
- [ ] Foundation removes duplicated webhook retry/signing composition where TalkingBytes can consume resolved values directly.
- [ ] Foundation removes duplicated email transport/fallback/retry/rate-limit/DKIM composition where TalkingBytes factories can consume resolved config.
- [ ] Foundation replaces manual EmailLimits construction with TalkingBytes parsing.
- [ ] Foundation keeps default From and notification/template routing as application policy.
- [ ] Foundation keeps HTTP clients scoped when mutable state is attached.
- [ ] Foundation routes inbound gRPC through the new host-controlled boundary.
- [ ] Foundation proves communication secrets are absent from generated metadata, cache keys and logs.
- [ ] Foundation adds direct-TalkingBytes-versus-Foundation bridge benchmark attribution.
- [ ] Foundation closes runtime plan point 26.9 only on exact-head green CI.

### Expected Foundation simplification targets

The follow-up should materially reduce logic in:

- src/Communication/CommunicationProfiles.php;
- src/Communication/CommunicationGraphFactory.php;
- src/Notifications/EmailProfiles.php;
- src/Notifications/NotificationGraphFactory.php.

Do not delete the host-policy parts of those classes merely to reduce line count.

---

## 21. Completion Gate

TalkingBytes 2.1 is complete only when:

- [ ] no primary runtime path depends on process-global CommunicationEventBus state;
- [ ] temporary global runtime state is scoped/restored and cannot span user/Fiber suspension paths;
- [ ] elapsed-time/deadline logic uses monotonic time where appropriate;
- [ ] retry/watch/stream/process waits support cooperative cancellation where materially useful;
- [ ] mutable protocol/session/resilience state has explicit lifetime semantics;
- [ ] sequential and Fiber-interleaved isolation tests pass;
- [ ] webhook replay is atomic/fail-closed by contract and tests;
- [ ] no CacheLayer/Foundation/Omnibus runtime dependency was introduced;
- [ ] inbound gRPC has a host-controllable accepted-exchange boundary;
- [ ] inbound gRPC wire errors do not reveal internal exception classes/messages/traces;
- [ ] generated gRPC adapter no longer relies on broad TypeError execution probing;
- [ ] native inbound/outbound email APIs remain authoritative;
- [ ] sendmail timeout/cancellation/process-tree cleanup is deterministic;
- [ ] posix use is optional and pcntl is not required/default;
- [x] HTTP multi scheduling uses a rolling concurrency window or the optimization is explicitly rejected with benchmark evidence;
- [ ] Foundation protocol-composition duplication has corresponding native TalkingBytes APIs ready for consumption;
- [ ] secret/PII sentinel tests pass across protocol observability;
- [ ] unrelated optional capabilities remain cold until selected;
- [ ] native protocol benchmark and soak evidence is recorded;
- [ ] PHPForge QA/static/security gates pass on supported PHP/dependency matrices;
- [ ] documentation builds warning-free;
- [ ] release metadata/examples match final APIs;
- [ ] the exact final commit is tagged only after the complete matrix is green.

---

## 22. Explicitly Out of Scope

Do not use 2.1 to add:

- another universal communication envelope;
- Foundation-specific service providers/configuration;
- CacheLayer, DBLayer or Omnibus runtime dependencies;
- an HTTP application server/framework;
- a general worker supervisor;
- a generic process-management package;
- fork-based HTTP/email concurrency;
- a custom gRPC wire implementation;
- application-specific named profile ownership;
- unrelated queue/messaging functionality;
- a major architectural rewrite that does not directly improve protocol correctness, runtime ownership, performance or Foundation integration.

---

## 23. Immediate Starting Point

Start with **Batch 1 — Runtime-state, clock and cancellation foundation**.

First objectives:

1. remove direct CommunicationEventBus dependence from SpoolEmailReceiver, mailbox runtime paths and BounceParser;
2. propagate injected EventDispatcher objects through native factories;
3. introduce the minimal cancellation contract and interruptible wait behavior;
4. move duration/deadline logic toward Clock::monotonic();
5. add sequential persistent-runtime and Fiber isolation tests.

Then do the gRPC wire-error correction early because the current exception-class response metadata is a concrete boundary leak.

Do not modify Foundation until TalkingBytes exposes the clean lower-layer APIs. Foundation should consume the released result afterward.
