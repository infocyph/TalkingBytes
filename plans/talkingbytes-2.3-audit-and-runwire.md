# TalkingBytes audit, hardening and Runwire integration plan

Audit date: 2026-10-02 (Asia/Dhaka). Status: Batches 1–6 complete and PR-verified. The tracker-closing head is tag-eligible only when its own CI remains green.

## Decision

Deliver **one complete 2.3.0 minor release** containing the security and correctness fixes, applicable hardening, execution-policy propagation, explicitly supplied Runwire integration, supported cooperative I/O paths, regression coverage, documentation and release verification. All implementation phases below belong to this single candidate; there is no separate 2.2.1 release or planned follow-up minor for the agreed integration scope. No finding currently requires a 3.0 rewrite or mandatory runtime dependency.

Implement the security fixes first, then complete the remaining phases before releasing 2.3.0. A passing existing suite does not cover the new reproductions below. Runwire `^2.1` is an authorized development dependency now; production use remains optional and explicitly bound to host-provided instances.

This plan follows [PHPForge engineering principles](../vendor/infocyph/phpforge/resources/engineering-principles.md) and its [agent workflow](../vendor/infocyph/phpforge/resources/AGENTS.md): preserve contracts, fix causes, keep integrations lazy, use existing owners, justify new types, preserve resource ownership, and require representative measurements for performance changes. Do not suppress findings, add baselines, lower analysis levels or relax detectors.

## Scope and evidence

The audit results in this section describe the original dependency set, before adding Runwire to `require-dev`. They remain baseline evidence, not validation of the eventual 2.3.0 implementation.

After the scope revision, Composer installed Runwire **2.1** under the `^2.1` development constraint, with no other package updates or removals. Strict Composer validation and autoload checks for its runtime, request and coroutine-scope types passed; Composer reported no security advisories. The full suite has not been rerun for this dependency-only revision.

- TalkingBytes HEAD and local `2.2` tag: `ed7873c033498132a169b792e63f601aef98d1ca`.
- The pre-existing `composer.json` change from `>=8.4` to `^8.4` was included in checks and left untouched. No production source or workflow was changed for this audit.
- Runwire HEAD and local `2.1` tag: `178308361772d4e995040a8ab84d4df876579078`; working tree clean. Runwire API conclusions below come from that exact source. Public package/tag pages could not be retrieved during browsing; this is not a claim about the latest remotely published version.
- Inventory: 290 tracked source PHP files, 61 tracked test/fixture files. PHPForge scanned 356 PHP files for syntax/references and 296 production/benchmark files for duplication/comments.
- Review combined library-wide configured automation, graph-based navigation, manual inspection of protocol/security/lifecycle boundaries and targeted adversarial probes. It is not a proof that every possible vulnerability has been eliminated.
- Runtime: host PHP 8.5.4, Composer 2.10.3, libcurl 8.18.0. Native gRPC and IMAP extensions absent; sockets, PCNTL, POSIX, Sodium, mbstring and iconv present.

| Check | Result and limitation |
| --- | --- |
| `composer ic:doctor`, `ic:list-config`, `ic:active-config` | Healthy; all 11 tool configurations resolve from installed PHPForge. |
| `composer validate --strict` | Passed. |
| `composer ic:tests:details`, sandbox | Exit 2: 7 failed, 40 skipped, 360 passed, 1,963 assertions. Local TCP fixture restrictions caused the test failures; retained as environment evidence. |
| `composer ic:test:code`, host access | Passed: **407 tests, 2,149 assertions**, no reported skips. |
| `composer ic:release:audit`, network access | Passed: **0 advisories**, one non-blocking abandoned dev dependency, `doctrine/annotations`, required by `phpbench/phpbench` 1.7.0. Initial restricted-network audit failed DNS and was not treated as a clean result. |
| `composer ic:release:guard`, host/network access | **Exit 0**. Validation, stable runtime constraints, audit, skip-directive scan, normalization check, syntax, references, duplicates, comments, Pest, Pint, PHPCS, Deptrac, PHPStan, Psalm security analysis and Rector dry run completed. |
| Duplicate detector | Passing under its existing `fail-on=error` policy, but reports **14 groups / 742 duplicated lines / 2.62%**; needs semantic triage, not dismissal. |
| Architecture detector | Passed, but generic Project/Vendor rules do not enforce protocol-module or optional-bridge boundaries. |
| Documentation | Warning-free Sphinx build passed in an isolated `/tmp` Python 3.14 environment using the pinned requirements. System Python lacked Sphinx and `ensurepip`; installed `uv` provided the isolated environment. |
| `composer ic:bench:quick` | Passed: **35 subjects, 0 failures, 0 errors**. Quick component execution only; no before/after candidate comparison or host RPM evidence. |
| New targeted probes | Confirmed the findings marked reproduced below; these were temporary audit probes, not additions to the existing 407-test suite. |
| Runwire feasibility probe | Real Runwire 2.1 scope passed through an intermediary function into TalkingBytes `RetryExecutor`: observed `attempt-1, host-peer, attempt-2`. Demonstrates cooperative retry waiting via the existing `Sleeper` seam; does not establish asynchronous network I/O or throughput gains. |

The native gRPC and Mailpit integration files return before registering tests unless their environment flags are enabled. The optional-capability test also has an inactive-mode assertion. Therefore **407 passing tests does not mean those dedicated CI jobs ran**. PHP 8.4, lowest dependency versions, minimal extensions, real Mailpit/native gRPC, final-revision CI, consumer integrations and sustained host benchmarks remain separate acceptance gates.

Audit logs are temporary local artifacts: `/tmp/talkingbytes-audit-host-tests.log`, `/tmp/talkingbytes-audit-release-guard.log`, `/tmp/talkingbytes-audit-dependencies-online.log`, `/tmp/talkingbytes-audit-docs.log`, `/tmp/talkingbytes-audit-bench.log`. Retain equivalent CI artifacts with the implementation PR; do not depend on `/tmp` for permanent release evidence.

## Required findings

Priorities describe remediation order, not externally assigned CVSS scores. Exploitability depends on the stated feature and trust boundary.

### F1 — Uploaded content survives a redirect that switches to GET

**High priority; reproduced over two different loopback origins.**

Owner: [`HttpRequest::redirectedTo()`](../src/Http/HttpRequest.php), lines 453–497, and [`CurlHandleConfigurator::applyUpload()`](../src/Http/Internal/CurlHandleConfigurator.php).

A POST uploaded with `uploadFromStream()` followed a 303 redirect. The destination received `GET` with the original `audit-sentinel` payload. `redirectedTo()` clears the body property and body headers, but retains `upload_stream`, `upload_file_path`, size and offset metadata. The configurator re-enables upload mode for the redirected request. A redirect target can therefore receive bytes that should have been discarded during the GET conversion. Redirects must be enabled for this path.

Fix in the existing request/transport owners: clear all upload-source and body-transfer metadata when converting to GET, and clear stale transfer headers. Preserve the existing repeatable-body semantics for 307/308. Never close caller-owned streams while dropping a body; release only library-owned handles and temporary files.

Acceptance: wire tests for file and stream uploads, 301/302 POST conversion, 303 conversion, HEAD preservation, 307/308 replay, same/cross-origin targets, stream offset and resource ownership. GET conversion must send zero upload bytes.

### F2 — Signed file/stream uploads authenticate the empty-body hash

**High priority; reproduced deterministically.**

Owner: [`SignedRequestAuth::payloadHash()`](../src/Auth/SignedRequestAuth.php), lines 80–97; upload preparation in `HttpRequest` and `CurlHandleConfigurator`.

Two different five-byte upload streams (`first`, `other`), with identical method/URL/timestamp/nonce/key, produce the same signature. Uploads are stored in metadata, so `$request->body === null` selects `sha256('')`. The returned signature does not bind the upload bytes. A verifier that independently hashes the actual wire body will reject these requests; one that mirrors the empty-body convention does not gain payload integrity. This is a confirmed signing gap, not evidence of a bypass in an independently correct server verifier.

For 2.3.0, support hashing the exact bounded upload range from the same prepared resource that will be sent, restoring caller offsets and retaining repeatability. Prevent a path replacement or stream mutation from making signing and transfer use different bytes. Fail closed for sources whose byte identity cannot be guaranteed; do not silently relabel them as unsigned. Any explicit unsigned-payload mode must require opt-in receiver policy and clear documentation.

Keep the existing multipart `UNSIGNED-PAYLOAD` contract distinct and clearly documented. Do not silently break that established wire contract in this minor release.

Acceptance: file/stream byte changes alter signatures; hashing respects offset/size; truncation and non-repeatable input fail safely; no full-buffer copies for large uploads; retries and redirects sign the bytes actually sent. Include receiver compatibility tests and migration notes for tightened behavior.

### F3 — MIME child headers bypass configured header limits

**Medium priority, resource-control gap; reproduced.**

Owners: [`RawEmailParser::assertHeaderLimits()`](../src/Email/Parser/RawEmailParser.php) and [`MimePartParser::parseRawPart()`](../src/Email/Parser/MimePartParser.php).

With `maxHeaderBytes=100`, `maxHeaderCount=2`, `maxHeaderLineBytes=80`, a multipart message containing a child `X-Large` field of 1,000 bytes parses successfully. Header limits are checked only on the root block. The overall message limit still bounds total input; this is a bypass of the finer header policy, not unlimited input acceptance.

Apply the declared header bounds before parsing every MIME header block. Define per-block versus aggregate semantics explicitly, share the existing validation at a cohesive parser boundary, and stop multipart enumeration before building an excessive intermediate parts array. Do not weaken depth, part-count or decoded-byte limits.

Acceptance: nested long lines, folded fields, too many child fields, nested multipart, boundary-size edges and aggregate decoded limits; valid MIME fixtures retain behavior.

### F4 — Supplied cancellation is ignored on important outbound paths

**Medium priority; reproduced with fake/native-shaped callers.**

- [`HttpClientFactory`](../src/Http/HttpClientFactory.php), lines 66–75, attaches its cancellation only to retry. With retry disabled and cancellation already true, a fake transport is called once and succeeds.
- [`Http/Middleware/RetryMiddleware`](../src/Http/Middleware/RetryMiddleware.php) bypasses cancellation when the method is not retry-safe.
- [`GeneratedStubGrpcInvoker::invoke()`](../src/Grpc/Native/GeneratedStubGrpcInvoker.php), lines 98–115, performs unary calls without the cancellation checks/cleanup present in its streaming siblings. An already-cancelled token still invokes the stub and returns status 0.
- gRPC callable/native factory paths and SMTP composition need the same explicit operation-boundary review; passing a signal into a factory is not proof that it reaches every transport.

Check cancellation independently of retry eligibility, before any business-side effect. Connect in-flight interruption only where the transport actually supports it. Add safe unary gRPC call cleanup around failure/cancellation without changing ownership of the caller's channel/stub. Cancellation must not become a generic retryable error or trigger a fallback send.

Acceptance: HTTP safe/unsafe methods, retries enabled/disabled, gRPC unary and each stream form, email retry/fallback chains; pre-cancelled work starts zero calls, no operation is replayed after cancellation, and host shutdown cannot leak handles. Document blocking-native limitations honestly.

### F5 — Deadline handling has gaps inside waits and between attempts

**Medium priority; reproduced.**

- [`ImapSocketTransport::readExact()`](../src/Email/Mailbox/ImapSocketTransport.php), lines 459–492, does not receive the command's absolute deadline. A six-byte literal delivered every 300 ms takes **1.80 s with a 1 s command timeout** before the outer loop notices expiry. Larger trickle responses can hold the worker much longer while individual reads avoid their idle timeout.
- [`Grpc/Middleware/RetryMiddleware`](../src/Grpc/Middleware/RetryMiddleware.php), lines 39–58 and 83–94, turns an expired budget into a fresh one-microsecond call. A deterministic sleeper that resumes at t=2 s under a one-second budget causes a second attempt at t=2 s.
- HTTP redirects/retries use per-attempt timeout options; they do not currently provide one propagated host request budget. Treat adding a new total-operation budget as an additive feature rather than silently redefining the old per-attempt option.

Use one monotonic absolute operation deadline across attempts, redirects, literal reads, retry sleeps and cleanup. Recompute remaining time immediately before each blocking call; bound that call to the remaining duration and check again after it returns. Check both remaining byte budget and literal size before allocating/reading a literal. Review SMTP/POP3 read/write loops for the same pattern.

Acceptance: trickle input, scheduler oversleep, expiry before first call, expiry during backoff, exact boundary, huge finite input and cancellation; expired work starts no new attempt. Cleanup has a bounded grace period and cannot renew the request deadline.

### F6 — IPv6 and redirect-path handling changes valid destinations

**Medium correctness priority; reproduced without remote traffic.**

Owners: [`RedirectResolver`](../src/Http/Internal/RedirectResolver.php), lines 31, 67–89, and [`RequestSecurityGuard::pinnedResolution()`](../src/Http/Internal/RequestSecurityGuard.php), lines 53–77.

- Resolving `/b` against `https://[2606:4700:4700::1111]/a` returns `https://[[2606:4700:4700::1111]]/b`.
- `/b//c/` becomes `/b/c`, changing empty path segments and the trailing slash.
- Strict private-network protection treats the bracketed public IPv6 literal as a DNS hostname and rejects it as unresolvable.

Normalize URL authority literals consistently; add brackets exactly once. Implement RFC 3986 reference resolution rather than filesystem `dirname()`/empty-segment stripping. Validate public literals directly and format each IPv6 address in cURL resolve entries correctly. While fixing literal recognition, normalize IPv4-mapped IPv6 through binary address representation so hexadecimal mapped private addresses cannot become newly allowed. Retain strict proxy isolation, fail-closed DNS checks, per-hop validation and rebinding protection.

Acceptance: the RFC reference-resolution vectors, IPv4/IPv6 and mapped addresses, empty segments, trailing slashes, query-only/fragment-only redirects, mixed public/private DNS answers, unresolved hosts, proxies and every redirect hop. Source standards: [RFC 3986 §5.2](https://www.rfc-editor.org/rfc/rfc3986.html#section-5.2), [cURL resolve-entry format](https://curl.se/libcurl/c/CURLOPT_RESOLVE.html).

### F7 — Resolved composition drops injected timing collaborators

**Medium integration priority; reproduced for HTTP rate limiting, source-confirmed elsewhere.**

[`HttpClientFactory`](../src/Http/HttpClientFactory.php), lines 78–91, constructs `RateLimiter` and `CircuitBreaker` without its injected clock. Advancing the supplied clock by two seconds does not refill a one-request-per-second limiter. `EmailSenderFactory` similarly omits its clock from the composed rate limiter. HTTP and email retry wrappers call `RetryExecutor` without forwarding a sleeper; factory gRPC retry composition does not consistently preserve the supplied clock/sleeper.

Thread the existing clock/sleeper/signal through every composed collaborator with optional trailing parameters or fluent methods. Preserve named arguments and existing constructor defaults. This is necessary for deterministic tests and for a Runwire binding to affect the whole composed operation.

Acceptance: the same injected clock controls cooldown/refill/elapsed behavior, the same sleeper controls all retry waits, explicit caller choices are preserved, and independent factory graphs never share mutable request state accidentally.

## Additional hardening and maintenance

These require implementation/triage but are not all confirmed exploitable vulnerabilities.

1. **Cookie integrity:** a plain HTTP response can overwrite an existing Secure cookie for the same key; the probe changes `session=secret; Secure` to `session=attacker` for a later HTTPS request. Review rejection of insecure-origin Secure cookies, insecure overlays and `__Secure-`/`__Host-` prefix constraints. Keep domain cookies disabled by default and retain the explicit parent-domain policy. Test against the declared cookie specification; do not claim full browser cookie semantics without implementing them.
2. **HTTP resource limits:** response-header collection has no package-owned aggregate byte/count budget; body/download limits default to unset. cURL has its own limits, so do not describe the header path as proven unbounded on every cURL version. Add configurable header budgets and a documented bounded profile for untrusted endpoints. Decide defaults with compatibility and representative payload evidence.
3. **Duplicate findings:** inspect all 14 groups. Reuse a core resolved-config parser for genuinely shared int/bool/section rules, preserve field-specific errors, consolidate repeated stream validation only where offset/ownership semantics match, and use private methods for repeated multi-transport cleanup. Several structurally similar guards represent different security contracts; do not merge them just to reduce a metric. Follow PHPForge's documented false-positive process where semantic duplication is not established, without exclusions or suppressions.
4. **Architecture enforcement:** define meaningful Core/Auth/Retry/Resilience/protocol/Runwire-integration boundaries if needed for the bridge. Keep mandatory core paths independent of Runwire. Generic current Deptrac success is not evidence that those boundaries are enforced.
5. **Build reproducibility:** the reusable PHPForge workflow is pinned to reviewed revision `18917f38bad206cf7d1bc119c20373bac3982433` and Mailpit to the candidate-tested image digest. The PHPForge Composer dev constraint intentionally remains `dev-main@dev`: a commit-ref constraint fails `composer validate --strict`, while this library does not commit a dependency lock. CI records the exact resolved PHPForge revision and the existing dependency-update workflow remains responsible for reviewing dev-tool movement. Treat this as supply-chain/reproducibility hardening, not a confirmed compromised dependency. Track the PHPBench/annotations warning upstream.
6. **Metadata/docs:** align README's `>=8.4` wording with the user's `^8.4` constraint, publish release notes and explicit compatibility/security tightening, and document inactive integration gates. Preserve existing public facades and aliases in the minor release.

Areas with useful existing protections include webhook v2 event/delivery binding, bounded signature candidates and secret rotation, atomic replay-store contracts, auth provenance/redaction, DNS/proxy policy, transport size limits, MIME depth/part/decoded-body limits, DKIM algorithm/key checks, mailbox command guards and sendmail process cleanup. Preserve their regression cases while changing composition.

## Runwire 2.1 integration design

### What Runwire actually provides

The checked 2.1 source exposes the following contracts, verified in the local sibling checkout: [`RuntimeContext`](../../Runwire/src/RuntimeContext.php), [`RequestContext`](../../Runwire/src/RequestContext.php), [`CancellationToken`](../../Runwire/src/CancellationToken.php), [`CoroutineScope`](../../Runwire/src/Coroutine/CoroutineScope.php) and [`CoroutineRuntime`](../../Runwire/src/Coroutine/CoroutineRuntime.php).

| Runwire contract | TalkingBytes use |
| --- | --- |
| `RuntimeContext::supports(RuntimeCapability)` | Evaluate the supplied host's capabilities. Context contains metadata/capabilities, not an event-loop handle. |
| `RequestContext::runtime()`, `deadline()`, `cancelled()`, `completed()` | Bind the operation to the same runtime and request lifetime; reject completed/mismatched request contexts. |
| `CancellationToken::isCancelled()`, `deadline()`, `onCancel()` | Adapt cancellation/deadline state; unsubscribe only TalkingBytes-owned subscriptions. |
| `CoroutineScope::cancellation()`, `sleep()`, `yieldNow()` | Use the supplied active task scope for cooperative retry/poll waits and child-task cancellation. |
| `CoroutineScope::waitReadable()/waitWritable()` | Candidate cooperative socket/process waits where TalkingBytes controls nonblocking resources. |
| `CoroutineScope::spawn()/group()/semaphore()` | Bounded child work only when its underlying I/O is cooperative and ordering/failure semantics remain correct. |

`CoroutineRuntime::run()` drives a loop and rejects nesting. `attachRequest()` creates a request scope on an existing scheduler. **Both belong to the host**, not to a protocol client borrowing an already active scope. `RuntimeCapability::SUPPORTS_HTTP2/HTTP3` describes runtime/server capability; it does not supply an outbound HTTP/gRPC transport or prove the installed cURL/native client supports that protocol.

### Proposed public contract

Add an optional fluent binding to existing composition entry points:

```php
// Proposed API; not available in the current release.
$httpFactory = $httpFactory->withRunwire(
    runtime: $runtimeContext,
    request: $requestContext,
    scope: $activeScope,
);

$client = $httpFactory->fromArray($resolvedHttpConfig);
$result = $client->send($request);
```

Use the same method vocabulary on HTTP, email sender/mailbox/receiver and gRPC factories, with webhook composition accepting the same binding or inheriting it from the bound HTTP client. Define one canonical binding path per facade and have convenience methods delegate to it. Do not create a competing all-protocol factory facade.

The proposed signature is `withRunwire(RuntimeContext $runtime, ?RequestContext $request = null, ?CoroutineScope $scope = null): self`. Runtime-only binding is valid and has normal synchronous behavior. A background task may supply runtime plus its active scope without an HTTP request. A request with no coroutine capability still contributes its deadline and cancellation.

For framework → intermediary library → TalkingBytes, pass the **same supplied objects** through ordinary parameters, or pass the already-bound factory/client to the intermediary. No framework-specific container dependency is needed. Do not construct a new Runwire instance from environment variables, extension presence, driver names or configuration arrays.

Use at most one small internal Runwire binding adapter initially, justified by the optional interoperability and request-lifetime boundary. Reuse `Clock`, `Sleeper`, `CancellationSignal`, `RetryExecutor` and existing transport contracts. Add a separate transport implementation only when it owns a real distinct I/O mechanism; do not introduce parallel runtime interfaces, a service locator, registries, schedulers or thin per-protocol adapter hierarchies.

### Ownership and precedence

- Host owns the server, worker pool, event loop, scheduler, request completion and supplied scope. TalkingBytes must not start/stop/run/reload/drain them, install signals, fork workers or call host `complete()`/scope `close()`.
- `ownsEventLoop`/`ownsWorkerPool` are facts about the runtime, never permission for the library to take ownership.
- TalkingBytes owns only resources it creates: its cURL handles, opened sockets, upload temporaries, and its own subscriptions/watchers. Cancel/unregister those handles only; never remove a host listener or close caller-owned streams/channels.
- Binding returns a new factory/client graph. Worker-scoped immutable configuration may be reused; request/scope-bound factories and closures are request/task-scoped. Do not put them into a worker singleton or process-global/Fiber-local registry.
- Preserve caller-selected transports and policies. Automatic cooperative selection applies only to the library's default path unless an explicit opt-in authorizes replacement.
- Compose explicit cancellation with host request/scope cancellation using logical OR. Use the earliest of explicit operation, host request and task-scope deadlines. A caller must not accidentally extend a host deadline.
- Keep security wall clocks real and separate from monotonic deadlines. Runwire's runtime context does not replace the wall clock used for webhook timestamps, signatures, cookies or DKIM expiry.
- A missing optional capability selects the normal bounded path **before I/O starts**. A timeout, cancellation, invalid binding or partly completed request must not cause a transparent retry through another transport; that can duplicate writes.
- Fail clearly on a completed request or invalid supplied scope. Runwire does not expose a general public proof that an arbitrary scope belongs to a particular request; require callers to pass the active scope from that request/task callback, test this contract, and do not inspect private scheduler state to fabricate such validation.
- Treat Runwire control-flow cancellation separately from business failures. Broad `Throwable` catches in retry/event/transport code must not swallow it, retry it or turn cancellation into success.

### Capability use and fallback

| Available input/capability | Automatic behavior | Fallback/limit |
| --- | --- | --- |
| No Runwire binding or package absent | Current standalone composition; no Runwire discovery or initialization. | Normal PHP-FPM/CLI behavior. |
| Runtime only | Validate/store immutable runtime metadata once; retain existing transport choice. | No request state or scheduler is invented. |
| Request context | Preflight cancellation, derive remaining operation budget, clamp protocol timeouts. | Native blocking calls remain blocking and must use bounded timeouts. |
| Active scope plus `RUNWIRE_COROUTINES` and usable loop capability | Adapt microsecond `Sleeper` input to `scope->sleep($micros / 1_000_000)`; use the same scope token and deadline. | Ordinary sleeper if no usable scope/capability; invalid supplied scope is an error. |
| Cooperative cURL driver validated | Drive bounded `curl_multi_exec` progress with nonblocking readiness/polling and host-scope waits. | Existing cURL transport/pool before start; no async claim for `curl_exec()` or blocking DNS. |
| Nonblocking mailbox/SMTP/sendmail streams validated | Wait through the supplied scope, preserving framing, TLS, partial-write, deadline and process cleanup semantics. | Existing bounded synchronous protocol path. |
| Native gRPC | Propagate remaining deadline and cancellation at available native boundaries. | Native `wait()/read()/write()` are not made asynchronous merely by a Fiber or scope. |

The current cURL multi implementation calls `curl_multi_select()` with up to 1 s or 50 ms waits. Injecting a cooperative `Sleeper` alone affects only its `select() == -1` fallback, so it is **not** sufficient for loop cooperation. Runwire 2.1 does not provide a ready-made outbound cURL/gRPC client.

For the first cooperative HTTP experiment, preserve existing request validation, cURL configuration, header/body collection, upload cleanup and result production; change only the drive/wait boundary. Use a bounded polling interval through the supplied scope if PHP's exposed cURL API does not provide a sound socket watcher integration. Measure CPU, wakeups and host fairness before adopting it. DNS pinning currently uses synchronous PHP DNS functions; retain that limitation explicitly until a safe bounded resolver integration is proven. Do not remove DNS pinning to obtain an asynchronous benchmark.

Do not automatically fan out stateful mailbox commands over one connection, share mutable cookie jars across tenants, share in-flight native calls, or multiply retries between the host and TalkingBytes. The existing pool's redirect rejection remains in place unless redirect support is separately implemented and verified.

### Dependency and optionality policy

- Runwire `^2.1` is now in `require-dev` and version 2.1 is installed for implementation and real integration tests. Keep it optional in production; add a `suggest` entry describing the available integration when the bridge is implemented. Do not make it a mandatory production requirement.
- Verify clean production installation/autoload with no Runwire and no dev packages. Optional foreign type references must not cause eager loading failures in ordinary APIs.
- Select/validate supported Runwire versions only when binding is requested. Do not reject an unrelated installed Runwire version in applications that never use this integration.
- Test minimum supported 2.1 and latest supported 2.x independently. Do not assume future 3.x compatibility.
- Runwire requires 64-bit PHP; apply that prerequisite to its integration path without silently raising TalkingBytes' standalone requirements.

## Implementation tracker

| Batch | Scope | Status | PR QA evidence |
| --- | --- | --- | --- |
| 1 | F1 upload redirect leakage + F2 exact-byte signed uploads | ✅ Complete | PR #16 run 36958385505: PHP 8.4/8.5 stable+lowest QA, analysis, benchmarks, clean install, Mailpit, native gRPC, docs and optional-capability coldness passed. |
| 2 | F3 MIME child-header limits + F6 RFC redirect/IPv6 correctness + cookie/resource hardening | ✅ Complete | PR #16 run 36961362557: PHP 8.4/8.5 stable+lowest QA, analysis, benchmarks, clean install, Mailpit, native gRPC, docs and optional-capability coldness passed after replacing flaky filesystem fixture readiness with a direct bounded stdout handshake. |
| 3 | F4 cancellation + F5 total deadlines + F7 collaborator propagation | ✅ Complete | PR #16 run 36966309267: PHP 8.4/8.5 stable+lowest QA, analysis, benchmarks, clean install, Mailpit, native gRPC, docs and optional-capability coldness all passed. |
| 4 | Optional Runwire 2.1 binding and lifecycle propagation | ✅ Complete | PR #16 run 36981793377: PHP 8.4/8.5 stable+lowest QA, analysis, benchmarks, clean install, Mailpit, native gRPC, docs and optional-capability coldness all passed on exact head `c8ab8bb1e4fa1545f6842a55912ef0a178fc06cc`. |
| 5 | Supported cooperative HTTP/socket/process I/O and lifecycle matrix | ✅ Complete | PR #16 run 36985198397: PHP 8.4/8.5 stable+lowest QA, analysis, benchmarks, clean install, Mailpit, native gRPC, docs and optional-capability coldness all passed on exact head `37706276780861360e0a53df5b30f5625140a0be`. |
| 6 | Duplicate/architecture/reproducibility/docs/performance/final release gates | ✅ Complete | PR #16 run 36987414120: PHP 8.4/8.5 stable+lowest QA, analysis, benchmarks, clean install, docs, Mailpit, native gRPC, optional-capability coldness, Runwire absent/present and explicit `ic:tests:details` → `ic:tests` → `ic:release:guard` acceptance all passed on implementation head `283df18c7fa0fd92e8d7ba8cb2c7427e7bb83b04`. |

Batch progression is strict: implement one batch, resolve its PR QA on the exact source head, update this tracker, then start the next batch. The PR remains open and unmerged until the final release gates pass.

## Implementation order and acceptance

### Phase 1 — Fix security and correctness

- [x] Turn F1–F7 reproductions into focused regressions in the existing HTTP signing/security/streaming, MIME limits, runtime cancellation, gRPC retry and IMAP literal test files.
- [x] Fix upload redirect and signing failures first, then parser/deadline/cancellation/URL/composition gaps. F1/F2 completed in Batch 1; remaining findings continue in later batches.
- [x] Cover cookie integrity and clarify resource-limit policy; distinguish safe bug fixes from newly configurable policies. Batch 2 blocks insecure Secure-cookie overlays/prefix violations and adds opt-in aggregate response-header budgets while preserving unbounded defaults.
- [x] Prepare the security section of the combined 2.3.0 release notes, including affected configurations and signed-upload tightening. The release notes describe the confirmed behavior changes without automatically publishing a vulnerability announcement; private reporting remains the disclosure path when an advisory is warranted.

### Phase 2 — Propagate execution policy through existing owners

- [x] Forward clock/sleeper/cancellation consistently through factories and decorators.
- [x] Add an additive total-operation deadline path with monotonic precision. Clamp cURL in milliseconds and gRPC in microseconds; never round an expired budget up to a fresh operation.
- [x] Keep existing result/exception contracts, with stable cancelled/deadline metadata and no secrets in events.
- [x] Triage duplicate groups in touched code, using cohesive shared owners rather than arbitrary class consolidation. Strict HTTP/gRPC resolved-value parsing and genuinely shared email section normalization now use `Core\\Support\\ResolvedConfig`; protocol-specific security/ownership guards remain separate.

### Phase 3 — Optional Runwire binding

- [x] Add the explicit fluent binding and one justified internal adapter.
- [x] Reuse host request/task instances through both direct and intermediary-library composition.
- [x] Capability checks occur once per binding where stable; live cancellation/deadline checks remain live.
- [x] Normal path stays cold when unbound; custom transports and stronger caller policy remain authoritative.
- [x] Complete automatic cancellation/deadline/cooperative retry waits as an internal milestone of the single release, with honest blocking-I/O documentation.

### Phase 4 — Complete supported cooperative I/O

- [x] Prototype host-cooperative cURL drive/wait using existing transport machinery. The promoted bounded-poll path passed parity/ownership/fairness coverage and the exact-head component benchmark gate; representative host performance remains a Phase 6 release gate.
- [x] Evaluate bounded nonblocking SMTP/mailbox/process waits independently; retain normal paths for mechanisms without proven support.
- [x] Do not advertise fully asynchronous gRPC, DNS, files or `mail()` based on coroutine availability.
- [x] Complete the supported cooperative HTTP/socket/process mechanisms in the same 2.3.0 candidate. Correctness, ownership, fallback and component benchmark gates passed in Batch 5; representative host performance remains part of Batch 6 before release.

### Phase 5 — Integration and lifecycle matrix

- [x] Runwire absent; installed but unbound; runtime-only; request-only execution binding; scope-bound request; background task scope; capability unavailable. Dedicated absent/present CI plus integration/coldness tests cover these states.
- [x] Framework → TalkingBytes, framework → intermediary → TalkingBytes, and two independent intermediary libraries receiving the same host context. Same-context intermediary rebinding is idempotent; independent protocol factories can borrow the same host context without taking ownership.
- [x] Sequential requests and interleaved tasks from different tenants: request cancellation/deadline binding is isolated, cross-context rebinding is rejected, and the existing mutable-state/runtime soak coverage remains clean for auth/cookie/mailbox/native/event-owned graphs.
- [x] Explicitly shared rate limits/circuits retain their intended host lifetime; dedicated Runwire lifecycle tests reuse the same limiter/breaker across independent request-bound clients and verify the state is preserved.
- [x] Pre-cancelled/expired/completed requests, cancellation during backoff/read/write/stream callbacks, scope shutdown, cleanup failures and attempted rebinding are covered across the Runwire, HTTP-pool, SMTP, IMAP, POP3, sendmail and gRPC regressions.
- [x] Host peer/timer keeps progressing during every path advertised as cooperative. HTTP-pool, retry, SMTP, IMAP, POP3 and sendmail tests exercise peer progress; resource cleanup and `WeakReference` soak tests verify completed request-bound graphs are not retained.
- [x] No loop lifecycle calls, worker creation, signal installation or host completion from library paths. A repository-owned Runwire architecture test confines dependency references and rejects lifecycle-ownership calls; cooperative tests reuse the borrowed scope after TalkingBytes work returns.

### Phase 6 — Performance, documentation and release

- [x] Record before/after component benchmarks and cold/unbound/bound construction cost. Run 36987414120 recorded PHPBench mode values for HTTP construction: PHP 8.4 unbound `0.200`, bound `0.906`, bound-resolved `19.646`, resolved-typed `18.657`; PHP 8.5 unbound `0.200`, bound `0.994`, bound-resolved `19.546`, resolved-typed `18.568`. These are CI component measurements, not production RPM.
- [x] Measure representative library-owned host paths where changed: real local HTTP fan-out, SMTP, IMAP, POP3 and sendmail fixtures exercise cooperative scheduling, while dedicated Mailpit/native-gRPC jobs cover external protocol integration. Webhook inherits the bound HTTP path. Production application concurrency curves remain host-owned and are not fabricated by the library.
- [x] Disposition host-level RPM/latency/capacity metrics explicitly: repository gates record exact revisions, component timing/memory, correctness, errors/timeouts, fairness and retention evidence; integrating applications must record successful RPM, p50/p95/p99, CPU/memory/queues/handles/downstream concurrency and deployment details on their production-equivalent host.
- [x] Apply the 2% regression threshold to the repository's matching PHPForge component benchmark comparison and require clean soak/resource behavior. A production-RPM 2% gate applies only where an integrating host supplies a matching application benchmark; the library does not invent a universal RPM baseline.
- [x] Require standalone/unbound behavior to retain practical performance parity. Runwire-absent clean installation/coldness and explicit unbound construction benchmarks preserve the simpler path; cooperative behavior is activated only by an explicit compatible binding.
- [x] Run the documented PHPForge processing workflow after source edits, targeted checks, `ic:tests:details`, final `ic:tests` and `ic:release:guard`; run 36987414120 passed the explicit release-acceptance job plus the standard PHPForge matrix. Current limits remain cognitive complexity 12/function, 80/class and 120/dependency tree.
- [x] Run `composer ic:ci`, release guard, docs warnings-as-errors, PHP 8.4/8.5 stable/lowest dependencies, clean `--no-dev` install and dedicated Mailpit/native-gRPC/minimal-extension jobs on the exact candidate revision. Run 36987414120 passed every required job on `283df18c7fa0fd92e8d7ba8cb2c7427e7bb83b04`.
- [x] Runwire absent/present and direct/transitive consumer jobs are part of CI and passed in run 36987414120. Host-owned native behavior is covered where claimed; no unsupported next-PHP production constraint is introduced.
- [x] Document the implemented API and include ownership, fallbacks, blocking limitations, mutable lifetimes, signature tightening, cancellation/deadline semantics, rebinding rules and host-owned performance responsibilities in the 2.3 documentation.
- [x] Enforce candidate freeze/tag policy: only an exact head whose required gates pass is tag-eligible. This PR remains open/unmerged and no tag is created here; the tracker-closing head must retain green CI before maintainer publication. Roll back optional integration through the unbound path or previous release if host RPM/timeout/queue/memory budgets regress; do not silently roll back necessary security controls.

## Completion criteria

The single 2.3.0 release is ready only when F1–F7, applicable hardening/maintenance work, execution-policy propagation, optional instance-based Runwire binding, supported cooperative I/O, direct/transitive composition, lifecycle/fallback parity, documentation and the required release gates are complete. Resolve or explicitly disposition every additional hardening item; a bare context adapter is not completion of the agreed scope. Cooperative transport claims require their own fairness and representative host performance evidence. Native capabilities that Runwire does not provide retain their documented bounded normal paths.

3.0 becomes appropriate only if implementation requires breaking existing public signatures, replacing transport/result contracts, removing supported standalone execution or changing established wire semantics outside security tightening. None is necessary for the proposed integration.

## Reproduction summary

The temporary audit scripts used production APIs except for the IMAP probe, which injected a loopback stream and invoked the private tagged-response reader using the same reflection pattern as the existing IMAP literal tests.

```text
303 upload, different loopback origin: GET body = audit-sentinel
Signed upload bytes first vs other, fixed clock/nonce: signatures equal
Nested MIME 1,000-byte header, configured 80-byte line limit: parsed
Cancelled HTTP factory, retry disabled: calls=1, successful=true
Cancelled generated gRPC unary invoker: calls=1, status=0
IMAP six-byte trickle literal, timeout=1s: error only after 1.80s
gRPC retry, deadline=1s, resumed at t=2s: second call timeout=0.000001s
IPv6 relative redirect: https://[[2606:4700:4700::1111]]/b
Redirect /b//c/: becomes /b/c
Strict public IPv6: rejected as hostname that cannot be resolved safely
Injected rate-limit clock advanced 2s: Rate limit exceeded
Secure cookie overwritten from HTTP: session=attacker
Borrowed Runwire scope retry: attempt-1, host-peer, attempt-2
```

These are reproduction observations on the audited revision. They must become persistent regression tests during implementation; the plan itself does not fix them.
