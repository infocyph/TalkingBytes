# TalkingBytes 2.1 — Foundation 3 Integration Hardening Plan

## Status

Target release: **TalkingBytes 2.1.x**

Baseline:

- branch: main
- tag: 2.0.0
- baseline commit: 86d0e9dde8124ddeacea8ba7f81911af584b879b
- primary consumer: Foundation 3 runtime plan point 26.9
- plan state: **PLANNED**

TalkingBytes 2.0 already established the intended protocol architecture. This is a focused 2.1 integration-hardening release, not another architectural rewrite.

The release goal is to make the existing HTTP, Email, Webhook and gRPC surfaces safe and explicit for persistent workers, Fibers and framework integration while keeping all protocol mechanics owned by TalkingBytes.

---

## 1. Release Policy

### 1.1 Compatibility

TalkingBytes 2.1 should remain additive and minor-release compatible wherever practical.

Priority order:

1. correctness
2. security
3. runtime isolation
4. protocol ownership
5. performance
6. scalability
7. API clarity
8. compatibility

If a security or correctness defect cannot be corrected compatibly, make the smallest necessary correction and document it explicitly.

### 1.2 Runtime floor

Keep PHP >= 8.4.

Keep infocyph/phpforge dev-main@dev in require-dev.

### 1.3 Dependency policy

TalkingBytes must remain framework-agnostic and lightweight.

Do not add Foundation, InterMix, CacheLayer, DBLayer, Omnibus, Pathwise or another Infocyph runtime library merely to simplify host integration.

Optional integrations remain behind native contracts and adapters.

---

## 2. Ownership Boundary

### TalkingBytes owns

- outbound HTTP request preparation and transport;
- redirect processing, HTTP streaming, retry and transport security;
- HTTP authentication, signing and cookie mechanics;
- inbound and outbound email protocol mechanics;
- SMTP, sendmail, PHP mail and spool transports;
- IMAP and POP3 mailbox behavior;
- MIME parsing, transfer decoding, charset decoding and attachment extraction;
- DKIM, authentication-result parsing and bounce classification;
- webhook signing, verification, timestamp validation and replay-store contract;
- gRPC request/response models;
- gRPC metadata, deadlines, status mapping, retry and stream mechanics;
- generated/native gRPC adapters;
- protocol fakes, assertion helpers, events and native benchmarks.

### Foundation owns

- named application profiles;
- capability selection and dependency activation;
- DI lifetime selection;
- application secret and configuration mapping;
- application path policy;
- CacheLayer-backed webhook replay-store implementation;
- worker heartbeat, stop and release-generation lifecycle;
- application service/handler mapping;
- application logging/audit policy;
- direct-versus-Foundation bridge benchmarks.

### Explicit non-ownership

TalkingBytes must not become:

- a Foundation-specific package;
- an HTTP application framework/server;
- a cache/database abstraction;
- a queue or worker supervisor;
- a home-grown gRPC wire stack;
- a process manager.

---

## 3. Verified Baseline Findings

### 3.1 Process-global event state remains in runtime paths

CommunicationEventBus stores a static dispatcher.

The current docs already describe this static bus as a compatibility adapter and recommend injected EventDispatcher objects for long-running workers. However, production runtime paths still call the static bus directly, including:

- Email/Receiver/SpoolEmailReceiver;
- Email/Mailbox/SocketMailboxRuntime;
- Email/Parser/BounceParser.

This is undesirable for persistent workers and Fiber-interleaved execution because one process-global listener can outlive the logical operation that installed it.

### 3.2 HTTP graph is immutable, selected collaborators are not

HttpClient uses immutable fluent composition, but selected collaborators intentionally contain mutable state:

- CookieJar;
- CircuitBreaker;
- RateLimiter;
- fake/spy transports.

This is correct functionality, but the lifetime contract is not explicit enough for host frameworks. Sharing such an instance at the wrong scope can leak cookie, breaker, limiter or test state between unrelated requests/jobs.

### 3.3 Webhook replay abstraction is correctly host-neutral

WebhookReplayStore::claim(namespace, deliveryId, ttlSeconds) already expresses the right lower-layer requirement:

- atomic first claim;
- bounded TTL;
- duplicate detection.

Foundation can implement the contract with CacheLayer without creating a TalkingBytes-to-CacheLayer dependency.

The contract should be hardened and tested, not replaced.

### 3.4 Inbound gRPC currently stops at dispatch

GrpcInboundDispatcher correctly maps normalized inbound requests to application handlers.

TalkingBytes does not yet expose a clean host-facing accepted-call/runtime boundary that a Foundation worker can drive one cycle at a time.

Foundation should not recreate gRPC protocol adaptation merely to fit its worker lifecycle.

### 3.5 Observability redaction is not uniformly strict

HTTP, webhook and mailbox paths already contain useful redaction, but some gRPC and email event paths can expose raw failure strings.

Raw exception messages may contain endpoints, metadata or credential-bearing lower-layer text. Runtime events should prefer structured and sanitized failure data.

### 3.6 Optional capability coldness needs release evidence

Native gRPC and several mail-related extensions/packages are optional by design.

The release must explicitly prove that unrelated protocol usage does not eagerly require or initialize optional capabilities.

---

## 4. Workstream 1 — P0 Runtime Event-State Isolation

### Goal

Injected EventDispatcher instances become the authoritative runtime observability mechanism.

CommunicationEventBus remains compatibility-only.

### Tasks

- [ ] Add or propagate optional EventDispatcher dependencies through native email factories where required.
- [ ] Convert SpoolEmailReceiver lifecycle events from static bus dispatch to an injected dispatcher.
- [ ] Convert mailbox command events away from direct CommunicationEventBus use.
- [ ] Convert BounceParser event emission away from direct CommunicationEventBus use.
- [ ] Audit every production src/ reference to CommunicationEventBus.
- [ ] Ensure newly created protocol/runtime code never requires process-global event state.
- [ ] Preserve CommunicationEventBus only as a compatibility facade.
- [ ] Keep dispatch best-effort: observer failures must never alter protocol results.
- [ ] Add sequential persistent-runtime tests proving event listeners do not leak between operations.
- [ ] Add Fiber-interleaving tests for execution paths that can overlap.
- [ ] Update events documentation to make injection the primary path.

### Acceptance

A normal object graph created through public factory/constructor APIs must not depend on static event state.

---

## 5. Workstream 2 — P0 Mutable-State Lifetime Contracts

### Goal

Document and test which objects are immutable, reusable or execution/session state.

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
| mailbox/socket transports | connection/session state | execution/worker owned |
| fakes/spies | mutable test state | test scoped |

### Tasks

- [ ] Add the lifetime matrix to architecture/runtime documentation.
- [ ] Prove fluent client operations never mutate previous instances.
- [ ] Prove cookie state exists only when a CookieJar is explicitly attached.
- [ ] Prove separate cookie jars do not cross-contaminate.
- [ ] Prove separate CircuitBreaker instances do not share counters.
- [ ] Prove separate RateLimiter instances do not share tokens.
- [ ] Add Fiber/interleaving tests around mutable collaborators.
- [ ] Keep shared resilience state an explicit host decision.
- [ ] Do not introduce a global resilience registry.
- [ ] Verify auth tokens and credentials are retained only inside explicitly configured client graphs.
- [ ] Ensure fake/spy state has deterministic new-instance/reset behavior.

---

## 6. Workstream 3 — P0 Webhook Replay Hardening

### Goal

Keep replay protection protocol-owned but storage-provider-neutral.

### Tasks

- [ ] Keep WebhookReplayStore minimal.
- [ ] Document that claim must be atomic across competing processes for production use.
- [ ] Document that replay backend errors must fail closed.
- [ ] Add a contention contract test where only one contender wins the same delivery claim.
- [ ] Add a throwing-store test proving WebhookReceiver does not silently bypass replay protection.
- [ ] Preserve strict positive TTL validation.
- [ ] Preserve bounded namespace and delivery-ID validation.
- [ ] Preserve signature/timestamp verification before replay claim.
- [ ] Preserve replay claim before a verified event is returned to application code.
- [ ] Ensure replay observability never exposes raw secret, signature or body data.
- [ ] Do not add CacheLayer as a TalkingBytes dependency.

### Foundation handoff

Foundation continues implementing WebhookReplayStore using CacheLayer atomic setIfAbsent under Foundation's own security cache-key domain.

---

## 7. Workstream 4 — P0 Host-Controlled Inbound gRPC Runtime Bridge

### Goal

Allow Foundation or another host to execute inbound gRPC through its own worker lifecycle without reimplementing TalkingBytes protocol adaptation.

GrpcInboundDispatcher remains the application dispatch boundary.

TalkingBytes does not become the worker supervisor.

### Target flow

native/server runtime
→ TalkingBytes inbound adapter/source
→ accepted gRPC exchange
→ normalized GrpcInboundRequest
→ GrpcInboundDispatcher
→ GrpcInboundResponse
→ native exchange completion

Exact class names may change while implementing. The ownership boundary must not.

### Required characteristics

- [ ] Add a small host-facing contract for obtaining/accepting one inbound gRPC exchange.
- [ ] The accepted exchange exposes a normalized GrpcInboundRequest.
- [ ] TalkingBytes owns mapping GrpcInboundResponse/status/metadata back to the native exchange.
- [ ] Provide a single-cycle or otherwise host-controllable execution API.
- [ ] Allow Foundation to check heartbeat, stop token and release generation between accepted calls.
- [ ] Do not hide an infinite process loop inside TalkingBytes unless cancellation/lifecycle control is explicit.
- [ ] Preserve gRPC method normalization.
- [ ] Preserve metadata mapping.
- [ ] Preserve deadline mapping.
- [ ] Preserve status/error mapping.
- [ ] Add a fake inbound source/exchange for deterministic integration tests.
- [ ] Do not add socket/process supervision.
- [ ] Do not require Foundation or Omnibus.
- [ ] Keep ext-grpc and generated-runtime dependencies optional until their adapters are selected.
- [ ] Document the exact inbound streaming modes actually supported.
- [ ] Do not claim inbound server/client/bidirectional streaming unless native inbound adapters implement them.
- [ ] If inbound streaming is added, keep it incremental, bounded and host-cancellable.

### Foundation handoff

Foundation wraps this one-cycle protocol boundary with the existing Foundation worker heartbeat, stop-token and release-generation lifecycle.

---

## 8. Workstream 5 — P1 Persistent-Runtime Email Hardening

### Goal

Keep TalkingBytes' full native inbound/outbound email system while making persistent-worker ownership explicit.

### Tasks

- [ ] Propagate injected EventDispatcher objects through EmailSenderFactory, EmailReceiverFactory and EmailMailboxFactory where required.
- [ ] Keep Emailer transport composition native to TalkingBytes.
- [ ] Keep SMTP/sendmail/mail/spool behavior native.
- [ ] Keep IMAP/POP3 mailbox behavior native.
- [ ] Keep MIME/parsing/DKIM/bounce behavior native.
- [ ] Define mailbox/socket connection ownership.
- [ ] Define logout/close/shutdown behavior for connection-bearing objects.
- [ ] Ensure one failed mailbox/session cannot poison subsequently constructed instances.
- [ ] Preserve bounded line/message/attachment/parse limits.
- [ ] Preserve spool processing/quarantine behavior.
- [ ] Preserve file locking and safe move semantics.
- [ ] Add sequential persistent-worker tests for sender, receiver and mailbox boundaries.
- [ ] Add Fiber/interleaving tests for stateless parser paths where useful.
- [ ] Prove no static event listener leaks across mail operations.

### Non-goal

Do not add Foundation-specific profile/config objects to TalkingBytes.

---

## 9. Workstream 6 — P1 Observability and Secret Redaction

### Goal

No default protocol event or log context should expose application secrets.

### Audit domains

- HTTP
- Webhook
- gRPC
- Email
- Mailbox

### Tasks

- [ ] Never emit raw Authorization credentials.
- [ ] Never emit raw bearer/API tokens.
- [ ] Never emit cookie values.
- [ ] Never emit proxy credentials.
- [ ] Never emit webhook secrets.
- [ ] Never emit raw webhook signatures.
- [ ] Never emit webhook bodies as observability fields.
- [ ] Never emit SMTP/mailbox passwords.
- [ ] Never emit raw authentication commands.
- [ ] Avoid raw gRPC metadata values unless explicitly classified safe.
- [ ] Stop blindly copying raw exception messages into protocol events.
- [ ] Prefer exception class, status/code and bounded sanitized categories.
- [ ] Keep caller-facing CommunicationResult detail useful; observability may deliberately be stricter.
- [ ] Add sentinel-secret tests and assert sentinel values never appear in emitted event/log payloads.
- [ ] Keep redaction overhead bounded on hot paths.

---

## 10. Workstream 7 — P1 Optional Capability Coldness

### Goal

Selecting one protocol must not eagerly activate another protocol's optional requirements.

### Acceptance matrix

- [ ] HTTP works without ext-grpc and grpc/grpc.
- [ ] Webhook works without native gRPC packages.
- [ ] Basic outbound email does not require IMAP-specific extensions.
- [ ] IMAP/POP3 optional capability checks occur only when those paths are selected.
- [ ] RSA DKIM does not require Sodium.
- [ ] Ed25519 DKIM fails clearly only when selected and Sodium is unavailable.
- [ ] Native/generated gRPC paths fail with actionable messages only when selected.
- [ ] Composer suggest metadata matches real runtime requirements.
- [ ] Documentation matches Composer optional capability metadata.
- [ ] Avoid unrelated extension/class probing on protocol hot paths.

---

## 11. Workstream 8 — P1 Native Benchmark Evidence

TalkingBytes owns native protocol benchmarks.

Foundation owns Foundation-bridge comparison benchmarks.

### HTTP benchmark coverage

- [ ] immutable client construction;
- [ ] request preparation;
- [ ] fake transport send;
- [ ] cookie-enabled send;
- [ ] retry middleware overhead;
- [ ] rate-limiter overhead;
- [ ] circuit-breaker overhead;
- [ ] repeated-run memory growth.

### Webhook benchmark coverage

- [ ] signing;
- [ ] verification;
- [ ] verification plus replay claim;
- [ ] duplicate rejection.

### gRPC benchmark coverage

- [ ] unary client dispatch;
- [ ] inbound dispatcher;
- [ ] new host inbound-exchange adapter;
- [ ] retry decision path;
- [ ] generated/native adapter overhead when available;
- [ ] streaming adapter overhead without eager stream materialization.

### Email benchmark coverage

- [ ] message preparation;
- [ ] null/fake send;
- [ ] parser;
- [ ] spool receive;
- [ ] deterministic mailbox adapter overhead where practical.

### Benchmark rules

- [ ] Do not add Foundation as a benchmark dependency.
- [ ] Separate CPU microbenchmarks from network/disk I/O.
- [ ] Record peak memory where meaningful.
- [ ] Add repeated-run checks for unexpected memory/state growth.
- [ ] Preserve clear ownership attribution.

---

## 12. Workstream 9 — Documentation and Release Metadata

- [ ] Update architecture docs with the lifetime/ownership matrix.
- [ ] Update events docs: injected dispatcher primary, static bus compatibility-only.
- [ ] Update webhook replay docs with atomic and fail-closed requirements.
- [ ] Update gRPC inbound docs for the host-runtime bridge.
- [ ] Update email docs for persistent-worker connection ownership.
- [ ] Update security docs with secret/redaction guarantees.
- [ ] Update performance docs with persistent-runtime guidance.
- [ ] Update testing docs with isolation and fake inbound-runtime examples.
- [ ] Update release checklist with static-state, optional-cold and secret-sentinel gates.
- [ ] Keep README examples aligned with the released API.
- [ ] Keep Composer requirements/suggestions synchronized with actual runtime behavior.

---

## 13. Likely File Touch Map

This is a planning map, not a requirement to modify every listed file.

### Core/events

- src/Core/Event/CommunicationEventBus.php
- src/Core/Event/EventDispatcher.php
- src/Core/Event/BestEffortEventDispatcher.php
- docs/events.rst

### Email

- src/Email/Email.php
- src/Email/EmailSenderFactory.php
- src/Email/EmailReceiverFactory.php
- src/Email/EmailMailboxFactory.php
- src/Email/Receiver/SpoolEmailReceiver.php
- src/Email/Mailbox/SocketMailboxRuntime.php
- src/Email/Parser/BounceParser.php
- relevant Email/Mailbox/Bounce tests

### HTTP/state

- src/Http/HttpClient.php
- src/Http/Cookie/CookieJar.php
- src/Http/Middleware/*
- src/Resilience/CircuitBreaker.php
- src/Resilience/RateLimiter.php
- relevant HTTP/resilience tests

### Webhook

- src/Webhook/Contracts/WebhookReplayStore.php
- src/Webhook/WebhookReceiver.php
- src/Webhook/WebhookVerifier.php
- src/Webhook/WebhookSender.php
- tests/Webhook*
- docs/webhook/*

### gRPC

- src/Grpc/GrpcInboundDispatcher.php
- src/Grpc/Receiver/*
- src/Grpc/Native/*
- src/Grpc/Testing/*
- tests/Grpc*
- docs/grpc/*

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

## 14. Execution Order

### Batch 1 — Runtime-state cleanup

- remove static-event dependency from primary runtime paths;
- propagate EventDispatcher injection;
- add persistent-runtime and Fiber isolation coverage;
- document lifetime semantics.

### Batch 2 — Secret and observability hardening

- audit protocol events/logs;
- remove raw secret/failure leakage;
- add secret-sentinel tests.

### Batch 3 — Webhook replay acceptance

- lock the atomic/fail-closed replay contract;
- add concurrency/error acceptance coverage;
- preserve storage-provider neutrality.

### Batch 4 — Inbound gRPC hosting boundary

- introduce accepted-call/source contract;
- connect it to GrpcInboundDispatcher;
- add fake source/exchange;
- prove host-controlled one-cycle execution;
- prove deadline/status/metadata mapping.

### Batch 5 — Email persistent-runtime acceptance

- complete event injection in mail paths;
- test connection/session ownership;
- retain native send/receive/parser behavior.

### Batch 6 — Optional cold graphs

- test protocols with unrelated optional dependencies absent;
- align errors, docs and Composer metadata.

### Batch 7 — Native benchmarks and docs

- expand native benchmark evidence;
- update architecture/security/testing/performance/release documentation.

### Batch 8 — Exact-head release gate

Run the complete PHPForge matrix and tag only after the exact final head passes every closure gate.

---

## 15. Foundation 3 Handoff

After TalkingBytes 2.1 is released:

- [ ] Foundation raises its communication floor from ^2.0 to ^2.1 only if the new host-facing APIs are required.
- [ ] Foundation keeps CommunicationProfiles as application composition only.
- [ ] Foundation keeps HTTP clients scoped when cookie/resilience state can be mutable.
- [ ] Foundation does not duplicate TalkingBytes HTTP retry, signing, cookie or transport mechanics.
- [ ] Foundation keeps the CacheLayer replay implementation in Foundation.
- [ ] TalkingBytes keeps only the replay contract.
- [ ] Foundation selects webhook verifier/receiver lifetimes according to actual state.
- [ ] Foundation routes inbound gRPC through its existing worker heartbeat/stop/release lifecycle using the new TalkingBytes host-runtime boundary.
- [ ] Foundation does not implement the gRPC network stack.
- [ ] Foundation continues using native TalkingBytes sender/receiver/mailbox APIs.
- [ ] Foundation proves communication secrets are absent from generated metadata, cache keys and logs.
- [ ] Foundation adds direct-TalkingBytes-versus-Foundation bridge benchmark attribution.
- [ ] Foundation closes runtime-plan point 26.9 only on the exact-head PHPForge matrix.

---

## 16. Completion Gate

TalkingBytes 2.1 is complete only when all of the following are true:

- [ ] no primary runtime path depends on process-global CommunicationEventBus state;
- [ ] mutable HTTP/resilience/session state has explicit lifetime semantics;
- [ ] sequential and Fiber-interleaved state-isolation tests pass;
- [ ] webhook replay is documented and tested as atomic and fail-closed;
- [ ] no CacheLayer or Foundation runtime dependency was introduced;
- [ ] inbound gRPC has a host-controllable adapter boundary suitable for Foundation workers;
- [ ] gRPC network/process ownership remains correctly outside TalkingBytes;
- [ ] native inbound/outbound email APIs remain authoritative;
- [ ] secret-sentinel tests pass across HTTP, webhook, gRPC, email and mailbox events/logging;
- [ ] unrelated optional capabilities remain cold until selected;
- [ ] native protocol benchmark evidence is recorded with correct attribution;
- [ ] PHPForge QA/static/security gates pass on the supported PHP/dependency matrix;
- [ ] documentation builds warning-free;
- [ ] release metadata/examples match the final API;
- [ ] the exact final commit is tagged only after the complete matrix is green.

---

## 17. Explicitly Out of Scope

Do not use 2.1 to add:

- another universal communication envelope;
- Foundation-specific service providers/configuration;
- CacheLayer, DBLayer or Omnibus as TalkingBytes runtime dependencies;
- an HTTP application server/framework;
- a general worker supervisor;
- a custom gRPC wire implementation;
- application-specific profile ownership;
- another major-version-scale redesign;
- unrelated feature expansion that does not improve TalkingBytes protocol correctness or the Foundation 3 integration boundary.

---

## 18. Immediate Starting Point

Start with **Batch 1 — Runtime-state cleanup**.

First objective:

1. remove direct CommunicationEventBus dependence from SpoolEmailReceiver, mailbox runtime paths and BounceParser;
2. propagate injected EventDispatcher objects through existing native factories;
3. add sequential persistent-runtime and Fiber isolation tests;
4. keep the static bus only as compatibility behavior.

Do not modify Foundation during this first batch. TalkingBytes should first expose the clean lower-layer behavior; Foundation should consume the released result afterward.
