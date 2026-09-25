# TalkingBytes 2.2 — Security, Protocol Correctness and Runtime Hardening

## Status and decision

**Audit date:** 2026-09-25. **State:** implementation in progress; Batch 1 HTTP trust-boundary work implemented; CI verification pending.

- Audited working revision: `bd2d198680e86cbac49425e898e591ed6194cbdb`.
- Latest version tag: `2.1`, resolving to commit `29fe13043225bfcf477adfa1f4dd1dc11fa4723f`.
- Five subsequent commits add typed HTTP resolved composition, tests, docs and benchmarks.
- Working tree was clean at audit start. This task adds this plan only.
- Governing instructions: [PHPForge engineering principles](../../vendor/infocyph/phpforge/resources/engineering-principles.md) and [agent workflow](../../vendor/infocyph/phpforge/resources/AGENTS.md).

**Recommendation:** release the complete additive hardening work as **2.2.0**. A narrowly scoped **2.1.1** backport can carry urgent security/data-integrity corrections on the released 2.1 branch without the new typed-composition APIs. Do not delay urgent fixes for optional optimization work. A **3.0.0** release is necessary only if implementation requires an incompatible established public API, configuration contract or supported-platform change. Do not call incompatible API removals a minor release merely because they improve security.

The library is not ready for another release unchanged. Twelve finding groups below have direct source evidence and local reproductions. They include security failures, data loss, protocol interoperability defects and unsafe combinations of otherwise valid settings. Severity is contextual, not a CVSS assessment. No compromise of a deployed application was established.

## Audit scope and evidence

All 290 production PHP files were included in the structural inventory and project quality tooling: Email 161, HTTP 49, gRPC 30, Webhook 21, Core 11, Retry 8, Auth 7 and Resilience 3. The suite contains 52 test files and six benchmark classes. Manual review concentrated on trust boundaries, transport lifecycle, protocol adaptation, mutable state, cryptography and externally controlled input. This is a risk-based library audit, not a proof that every input or integration is safe.

| Area | Reviewed boundaries | Result / remaining limit |
| --- | --- | --- |
| HTTP/auth | URL/network policy, proxies, redirect credentials, cookies, downloads, request signing, upload handling, rolling pool, retry safety | F01–F05; additional redirect/upload and IPv6 probes required during implementation |
| Webhook | v2 identity binding, downgrade rejection, timestamp acceptance, replay-store contract and expiry | Existing v2 correction retained; F12 concerns retention settings |
| Email outbound | SMTP TLS/auth, command limits, envelope delivery, sendmail process execution, spool output, retries/fallbacks | F09; process-tree and ambiguous-delivery work remains explicitly bounded below |
| Email inbound | MIME limits, attachments, mailbox validation, IMAP mutation, spool claiming, DKIM, authentication-result parsing | F07–F08, F10–F11; parser memory and spool filesystem follow-ups below |
| gRPC | generated method shape, native streaming completion, metadata bounds, deadline/retry policy, inbound exception redaction | F06; real native integration remains required |
| Core/resilience | injected dispatch, temporary error handlers, monotonic duration clocks, cancellation, cookie/rate/circuit lifetimes | Existing isolation tests pass; targeted clock and lifecycle follow-ups below |
| Packaging/CI | runtime requirements, optional extensions, dependency audit, PHPForge, workflow, version history | Baseline passes locally; current HEAD has no exact-commit workflow run |

### Verified baseline

- Host: Linux, PHP **8.5.4 NTS**, libcurl **8.18.0**, OpenSSL **3.5.5**.
- Installed PHPForge: `dev-main` at `fdec64cf4460f13116eb0e2f3405acadd3e84377`.
- Ran `composer ic:doctor`, `composer ic:list-config`, `composer ic:active-config`.
- `composer ic:release:guard`: **PASS, 370 tests / 1,935 assertions**, including normalization, skip scan, syntax/reference/duplicate/comment checks, formatting, architecture, PHPStan, Psalm and Rector checks.
- Composer audit: **zero advisories**; one non-blocking abandoned development dependency, `doctrine/annotations`.
- The sandbox itself could not start (`mountinfo path is not absolute`); audit commands ran with approved host access. No product failure is inferred from that sandbox error.
- Local probes used only temporary files, synthetic keys/credentials, fake sendmail, in-process doubles and localhost servers. No real email or third-party mutation was performed.
- GitHub API query for current HEAD returned **zero workflow runs**. The [green run for released 2.1](https://github.com/infocyph/TalkingBytes/actions/runs/35757809396) does not validate the five later commits or future fixes.
- This audit did not rerun PHP 8.4, live Mailpit, native ext-grpc integration, minimal-extension lanes, docs builds or production-equivalent throughput/soak measurements. These are acceptance gates, not assumed successes.

Temporary probe material was recorded under `/tmp/tb-audit/` and the guard log at `/tmp/tb-audit-guard.log`. Those paths are session evidence, not durable release artifacts. The setup, observation and required regressions below are the durable record; implementation must promote them into repository tests.

## Engineering constraints

1. Correctness, security, data integrity and operational stability precede performance and compatibility convenience. Preserve explicit protocol semantics.
2. Work in existing cohesive owners. Default production type budget is **zero new public types**. An optional cookie-domain or sensitive-field contract needs a concrete substitution/security-boundary justification before introduction. Do not build a general security framework or process manager.
3. Keep PHP >=8.4, framework independence, optional gRPC/mail/POSIX/Sodium capabilities and cold loading. Do not add Foundation or another runtime library to solve these defects. Keep `infocyph/phpforge` in development dependencies.
4. Preserve public parameter names and constructor compatibility where possible. Use additive options for new policy. Document stricter rejection of unsafe inputs and any changed transport semantics.
5. Do not weaken detectors, raise complexity thresholds, add baselines/suppressions, or remove relevant assertions to obtain green checks. Use the installed active configuration as authoritative.
6. Use deterministic negative tests and independent protocol vectors. A library signer agreeing with its own verifier, or a fake exposing methods the real native class lacks, is insufficient evidence.
7. Benchmark meaningful hot-path changes and complete operations. Measure successful work, errors, latency, memory and cleanup separately. Do not translate CPU microbenchmarks into application RPM claims.
8. No broad style rewrite, source-file consolidation, runtime dependency upgrade, framework integration or public API removal is part of this plan merely for tidiness.

## Required findings and remediation

### F01 — Redirect credentials and custom authentication redaction

**Priority P0; high security impact when redirects or observability are enabled.**

Owners: `src/Http/HttpRequest.php:433`, `src/Auth/ApiKeyAuth.php`, `src/Auth/SignedRequestAuth.php`, `src/Http/Support/HttpRedactor.php:42`, HTTP event/logging call sites.

**Reproduction:** prepare a request with `ApiKeyAuth('X-API-Key', 'audit-secret')`, redirect to another origin with authentication preservation disabled, then inspect the prepared headers. The API key remains. A real localhost redirect from host `127.0.0.1` to host `localhost` delivered that key to both origins. Preparing `ApiKeyAuth('X-Vendor-Credential', 'audit-secret')` and passing its headers through the event redactor also retains the secret. Current code removes/redacts fixed header names, but built-in authenticators support arbitrary names.

- [x] Track the credential-bearing header/query fields supplied by native authenticators in the request's existing ownership flow; bound any added metadata.
- [x] Strip all native authentication outputs on an origin change and apply credential policy consistently before each hop. Account for custom signature-header names and explicit sensitive headers.
- [x] Use the same sensitivity information in request-start events, logging middleware, URL redaction and webhook HTTP events. Redact nested query credentials when the auth API can create them.
- [x] Add a documented extension route for caller-provided authenticators; do not guess that every custom header is harmless or remove every application header indiscriminately.
- [x] Test host/port/scheme changes, same-origin retention, API-key headers and queries, custom signature fields, failed requests, callbacks, and sentinel secrets in every emitted payload.

**Acceptance:** the redirect target and observers never receive credentials outside their intended scope. Redirects remain disabled by default. No secret values appear in exceptions introduced by validation.

### F02 — Strict private-network policy inherits environment proxies

**Priority P0; high security impact when the host environment configures a proxy.**

Owners: `src/Http/Internal/RequestSecurityGuard.php:35`, `src/Http/Internal/CurlHandleConfigurator.php:41`.

**Reproduction:** start a fake HTTP proxy on loopback; set `http_proxy` to it and clear `no_proxy`; send `HttpRequest::get('http://8.8.8.8/')->blockPrivateNetworks()`. The request succeeds with cURL `primary_ip=127.0.0.1`. No connection to 8.8.8.8 is made: the local proxy answers the request. Explicit proxies are rejected, but inherited proxies are not disabled, so local DNS pinning does not establish the destination used by a remote-resolving proxy.

- [x] When strict network blocking is selected, explicitly disable inherited proxies on the handle or reject the combination before transport. Keep the non-strict proxy behavior deliberate and documented.
- [ ] Test `http_proxy`, `https_proxy`, `ALL_PROXY`, `NO_PROXY`, explicit proxies, single transport and multi transport in isolated child environments. Never change process-global proxy variables inside normal client code.
- [x] Extend the same security tests to public/private DNS answers, IPv4-mapped IPv6, literal IPv6, trailing-dot hosts, redirects and DNS changes. Treat these as coverage requirements, not additional proven bypasses.

**Acceptance:** strict mode cannot delegate destination resolution to an unvalidated proxy. See [libcurl proxy behavior](https://curl.se/libcurl/c/CURLOPT_PROXY.html).

### F03 — Cookie origin attribution and optional domain-cookie isolation

**Priority P0 for redirect attribution; P1 for explicit domain-cookie mode. High/conditional security impact.**

Owners: `src/Http/HttpClient.php:243`, `src/Http/Transport/CurlTransport.php`, `src/Http/Cookie/CookieJar.php:316`.

**Reproduction A:** the loopback redirect above returns `Set-Cookie: session=target-cookie; Path=/` from `localhost`. The default jar stores it as a host-only cookie for **127.0.0.1**, the original host, because `HttpClient::send()` attributes the final response to the starting URL.

**Reproduction B:** with `allowDomainCookies:true`, a response from `attacker.co.uk` setting `Domain=co.uk` installs `session=injected`; the jar sends it to `victim.co.uk`. Default host-only mode avoids this second case.

- [x] Attribute each response's cookies to its actual origin. Prefer per-hop cookie storage/application so intermediate cookies and path/security rules remain correct.
- [x] Do not trust an unrelated result field or arbitrary caller metadata as authoritative provenance without a clear transport contract.
- [x] Re-evaluate Cookie headers at each redirect; test path changes, HTTPS downgrade policy, return-to-origin chains and intermediate responses.
- [x] Keep domain cookies disabled by default. For opt-in domain cookies, require an effective public-suffix policy or explicit allowed parent-domain policy; fail closed where policy cannot establish the boundary. Do not ship a short hard-coded suffix list as complete coverage.
- [x] Test public suffixes, private suffixes, IP hosts, host-only cookies and legitimate subdomain sharing.

**Acceptance:** a redirect target cannot set cookies for the starting origin, and an unrelated registrant cannot set shared cookies through a public suffix. [Cookie storage rules](https://www.rfc-editor.org/rfc/rfc6265#section-5.3) provide the protocol reference.

### F04 — Failed downloads replace existing files

**Priority P0; high data-integrity impact.**

Owners: `src/Http/Internal/ResponseBodyCollector.php:95`, `src/Http/Transport/CurlTransport.php:268`, `src/Http/Concurrent/CurlMultiTransport.php:331`, `src/Http/Internal/CurlResultFactory.php:48`.

**Reproduction:** prefill a target with `KEEP-ORIGINAL`. A local server declares `Content-Length: 100` but sends `PART` and closes. Both single and multi streamed downloads report failure yet replace the target with `PART`. The collector promotes the temporary file before the transport decides success. Buffered download writes also precede the cURL error check by source inspection.

- [x] Separate close/flush from commit. Promote only after transport completion and the documented accepted-response policy are known.
- [x] Abort temporary output on cURL failure, cancellation, invalid redirects, callback failure and configured size violations.
- [x] Give buffered downloads the same atomic replacement guarantees and failure ordering.
- [x] Define whether HTTP error bodies are saved; prefer an explicit opt-in when saving an error response would overwrite a successful artifact. Preserve documented behavior where a separate artifact API is necessary.
- [x] Test truncated bodies, resets, connect failures, timeouts, zero-byte success, HTTP errors, disk failures and cleanup in single/multi modes.

**Acceptance:** unsuccessful transfers preserve existing targets; new failed targets do not appear; temporary resources are released. Successful output is published atomically.

### F05 — Empty successful HTTP responses become transport failures

**Priority P1; medium correctness impact.**

Owner: `src/Http/Transport/CurlTransport.php:145`.

**Reproduction:** a local server returns `204` with no body. Single transport returns `successful=false`, `status=null`, `cURL request failed (0):`; multi transport correctly returns status 204 and success. Empty redirect responses can fail before following their Location. The single transport treats a non-string callback-based cURL return plus an empty collected body as failure independently of cURL status.

- [x] Distinguish `curl_exec() === false`/cURL errors from a valid empty collected body.
- [x] Preserve status and headers on valid bodyless responses.
- [x] Add single/multi parity for HEAD, 204, 304, zero-length 200 and empty 301/302/307/308 with redirects both enabled and disabled.

**Acceptance:** valid empty HTTP responses remain valid results; actual transport failures remain failures.

### F06 — Generated gRPC stream completion assumes the wrong native API

**Priority P1; medium/high integration availability impact.**

Owner: `src/Grpc/Native/GeneratedStubGrpcInvoker.php:315`; tests in `tests/GrpcGeneratedStubInvokerTest.php`.

**Reproduction:** a native-shaped server-stream call exposing `responses()`, `getStatus()`, metadata accessors and `cancel()`, but no `wait()`, delivers its messages and then throws `Unsupported gRPC call object: expected wait() method.` Real upstream server/bidirectional streaming calls use `getStatus()`. This audit used an upstream-shaped double; it did not run ext-grpc against a real server.

- [ ] Resolve completion strategy for unary/client streaming versus server/bidirectional streaming without invocation-and-catch probing.
- [ ] Map final status, details and trailers exactly once; preserve non-OK stream results and cleanup on callback exceptions.
- [ ] Fix native-shaped doubles so unsupported synthetic methods do not conceal adapter defects.
- [ ] Add an optional real grpc/grpc + ext-grpc integration lane for all four call shapes and cancellation/deadline cleanup.
- [ ] Independently test bidirectional flow control: current write-all-then-read ordering needs an interactive peer test. Do not claim general duplex support from a batch fake. If true duplex needs an incompatible public contract, split that feature into the major-version decision gate rather than silently buffering streams.

**Acceptance:** real supported generated clients complete successfully and report status/trailers accurately. Reference: [upstream ServerStreamingCall](https://github.com/grpc/grpc/blob/master/src/php/lib/Grpc/ServerStreamingCall.php), [BidiStreamingCall](https://github.com/grpc/grpc/blob/master/src/php/lib/Grpc/BidiStreamingCall.php), [ClientStreamingCall](https://github.com/grpc/grpc/blob/master/src/php/lib/Grpc/ClientStreamingCall.php). Pin concrete supported package revisions when creating fixtures.

### F07 — Ed25519 DKIM signs raw canonical input instead of its SHA-256 digest

**Priority P1; medium interoperability impact.**

Owners: `src/Email/Dkim/DkimSigner.php:151`, `src/Email/Dkim/DkimVerifier.php:347`.

**Reproduction:** a generated Ed25519 signature verifies with Sodium over the raw canonical header input but fails over `hash('sha256', $input, true)`. Both library directions implement the same nonstandard operation, so a round-trip test misses it.

- [ ] Sign and verify the binary SHA-256 digest using PureEd25519 as specified in [RFC 8463 section 3](https://www.rfc-editor.org/rfc/rfc8463.html#section-3).
- [ ] Add the RFC's independent vectors and interoperability checks with another implementation; retain RSA coverage and cold Sodium behavior.
- [ ] Reject the old nonstandard representation rather than silently trying multiple algorithms. Document the correction for consumers exchanging historical library-generated signatures.

**Acceptance:** externally generated standards-compliant signatures verify, and library output verifies externally. This is not evidence of private-key exposure.

### F08 — Additional DKIM policy and canonicalization errors

**Priority P1; medium policy/interoperability impact.**

Owners: `src/Email/Dkim/DkimVerifier.php:147`, `src/Email/Dkim/DkimPublicKeyParser.php`, `src/Email/Dkim/DkimSignatureValidator.php`, `src/Email/Dkim/DkimCanonicalizer.php`.

Independent RSA/Sodium probes and byte checks found:

1. A key record containing `t=s` still accepts a signature with `d=example.com; i=user@sub.example.com`. Key flags are discarded before signature identity policy is evaluated. A valid key/signature is required; this is policy non-enforcement, not signature forgery.
2. A correctly constructed `h=from:from` signature with one actual From header is rejected. An absent oversigned occurrence must contribute no bytes, not invalidate verification.
3. Empty relaxed bodies canonicalize to hex `0d0a`; the required relaxed result is empty. Both signer and verifier repeat this error.

- [ ] Preserve and enforce relevant key constraints at the verification boundary.
- [ ] Implement absent-header and empty-body semantics without relaxing mandatory From coverage.
- [ ] Reuse the existing canonicalization owner where appropriate; avoid two subtly different implementations.
- [ ] Add independent vectors for all three cases and negative variants.
- [ ] During the same focused review, cover `verifyAll()` retaining other signed DKIM fields, folded signature whitespace, optional key version tags, ambiguous DNS records and revoked keys. These additional cases are review targets, not all reproduced findings.

**Acceptance:** key policy is enforced and valid independent messages interoperate. Reference: [RFC 6376](https://www.rfc-editor.org/rfc/rfc6376), sections 3.4.4, 3.5 and 3.6.1.

### F09 — Sendmail silently omits Bcc envelope recipients

**Priority P1; medium delivery-correctness impact.**

Owners: `src/Email/Transport/SendmailTransport.php:64`, `src/Email/System/EmailHeaderBuilder.php`, `tests/TransportProcessCoverageTest.php`.

**Reproduction:** a fake sendmail captures argv and stdin for a message with To and Bcc. Arguments are `-t`, `-i`, `-fsender@example.com`; Bcc is absent from both argv and MIME input, yet `acceptedRecipients` includes the Bcc address. The fake sent no email. A recipient not supplied through either channel cannot be delivered by sendmail.

- [ ] Pass validated envelope recipients, including Bcc, through sendmail's supported argument semantics without exposing Bcc in the delivered MIME message.
- [ ] Avoid duplicate recipient derivation when combining explicit recipients with `-t`; define allowed extra-argument behavior and option termination.
- [ ] Test To/Cc/Bcc-only/mixed messages, special-but-valid addresses, envelope sender, nonzero exit and cancellation. Add a real compatible sendmail integration fixture where available.

**Acceptance:** recipients marked submitted were actually supplied to the child; Bcc remains private. Do not interpret process acceptance as proof of final remote delivery.

### F10 — IMAP move fallback expunges unrelated deleted messages

**Priority P0; high data-loss impact on servers without MOVE.**

Owner: `src/Email/Mailbox/ImapSocketTransport.php:192`.

**Reproduction:** a local server advertises `IMAP4rev1 UIDPLUS` but no MOVE. Moving UID 2 produces `UID COPY 2`, `UID STORE 2 +FLAGS (\\Deleted)`, then bare `EXPUNGE`. The last command can permanently remove other messages already marked deleted; UIDPLUS was available but unused.

- [ ] Use UID-scoped expunge when UIDPLUS is available, preserving native UID MOVE when supported.
- [ ] Without a safe scoped mechanism, fail before destructive mutation or offer an explicitly documented copy/mark-only operation. Do not temporarily manipulate all other deleted flags as a supposedly atomic workaround.
- [ ] Test unrelated deleted UIDs, concurrent flag changes, partial COPY/STORE failures and capability combinations; report partial outcomes honestly.

**Acceptance:** moving one UID never expunges another UID. Reference: [RFC 4315 UID EXPUNGE](https://www.rfc-editor.org/rfc/rfc4315.html#section-2.1).

### F11 — Spool read locking does not establish exclusive consumption

**Priority P1; medium duplicate-processing/data-integrity impact for overlapping consumers without a processing directory.**

Owner: `src/Email/Receiver/SpoolEmailReceiver.php:232`; `docs/email/spool-receiver.rst`.

**Reproduction:** configure `lockBeforeRead:true`, `deleteAfterRead:true`, no processing directory. A parser invokes a second receiver after the first has read the file but before finalization. Both return the same message. This deterministically models overlapping consumers: the lock is released before parse/finalize and cannot provide the documented worker safety by itself.

- [ ] Define exclusive consume ownership separately from peek/read locks. Prefer an atomic claim before reading; retain ownership through success/failure handling.
- [ ] Handle losing a claim as contention, not a malformed email; do not quarantine another worker's input.
- [ ] Test independent processes, crash after claim, parse failure and cleanup. State delivery semantics explicitly; do not promise exactly-once application processing.
- [ ] Audit symlink acceptance, canonical directory overlap, target collisions and rename across filesystems while touching this lifecycle. These are source-review concerns needing dedicated tests, not all proven exploits.

**Acceptance:** concurrent receive operations cannot return the same claimed source; failures are recoverable under the documented policy. Peek must remain non-consuming.

### F12 — Replay claims can expire while signatures remain acceptable

**Priority P1; medium conditional security impact for short/custom TTL settings.**

Owners: `src/Webhook/WebhookReceiver.php`, `src/Webhook/WebhookVerifier.php`, `src/Webhook/Replay/InMemoryWebhookReplayStore.php`.

**Reproduction:** inject the same clock into verifier and store; use verifier max age 300 seconds and replay TTL 1 second. Accept a signed delivery at time 1,000,000, advance two seconds, and replay unchanged headers/body. It is accepted again. Default TTL 86,400 with default tolerance avoids this specific configuration; constructor validation currently permits the unsafe pair.

- [ ] Ensure claims cover the complete remaining signature-acceptance window, including accepted future timestamps and boundary precision. Derive effective retention or reject unsafe combinations through an additive policy API.
- [ ] Keep protocol timestamp validation on wall time; use monotonic duration accounting for process-local retention where appropriate. Document distributed backend TTL semantics and limits.
- [ ] Test future/old timestamp boundaries, short TTL, fractional timestamps, wall-clock jumps, backend failure and duplicate contention.
- [ ] Preserve provider neutrality, strict v2 event/delivery binding and fail-closed store behavior.

**Acceptance:** a delivery cannot become replayable while its signature is still accepted under the configured policy.

## Measured improvements and bounded follow-ups

These do not excuse delaying P0 fixes and are not claims of additional proven vulnerabilities.

| ID | Work | Evidence / exit condition |
| --- | --- | --- |
| I01 | Reduce repeated DNS validation and improve literal IPv6 handling | `pinnedResolution()` calls validation and resolution again; benchmark and test one validated address snapshot per hop before changing ownership. Preserve all-address checks and DNS pinning. |
| I02 | Establish parser peak-memory limits under adversarial MIME shapes | Multipart splitting materializes lines/parts before later traversal limits. Measure many tiny parts, long lines and maximum-depth messages in a bounded child process. Fix only measured amplification that exceeds an explicit memory budget. |
| I03 | Make retry/fallback outcomes explicit for partially accepted email | Examine SMTP accepted/rejected recipients, transport timeout after DATA and whole-message fallback. Avoid duplicates without inventing exactly-once SMTP guarantees; add replayability tests for attachment streams. |
| I04 | Verify cancellation and deadline bounds against real blocking I/O | Exercise SMTP slow peers, gRPC blocked reads/writes, sendmail descendants and graceful/forced termination. Report where native APIs only permit between-call cancellation. Do not add implicit PCNTL or global signal handlers. |
| I05 | Review resource-default compatibility | HTTP body/download limits default to null. Benchmark bounded secure profiles; introducing mandatory lower defaults for established users requires a migration/API decision, not an unexplained cap. |
| I06 | Strengthen misleading integration examples and independent fixtures | Keep parsed Authentication-Results explicitly untrusted unless the host establishes provenance; existing security docs already warn about this. Extend practical examples. Keep this distinct from DKIM cryptographic validation. |
| I07 | Review structure using PHPForge facts | Reuse typed HTTP composition, existing transport owners and canonicalizers. Consolidate only proven redundant work in touched paths; no architecture rewrite based on the count of 290 files. |

## Implementation batches

Each batch is reviewable independently; add a failing regression before changing behavior. No batch is complete solely because the pre-existing suite stays green.

| Batch | Scope | Status |
| --- | --- | --- |
| 1 | HTTP trust boundaries — F01-F03 | Implemented; CI verification pending |
| 2 | HTTP transfer correctness — F04-F05 | Implemented; CI verification pending |
| 3 | Email data integrity — F09-F11 | Pending |
| 4 | Protocol interoperability — F06-F08 | Pending |
| 5 | Replay policy — F12 | Pending |
| 6 | Measurement/docs/release | Pending |

1. **HTTP trust boundaries:** F01–F03. Establish provenance/sensitivity handling once in existing owners; verify redirect chains and environment isolation.
2. **HTTP transfer correctness:** F04–F05. Shared commit/abort policy and single/multi response parity; include redirects and uploads in affected lifecycle tests.
3. **Email data integrity:** F10 then F09 and F11. Targeted IMAP operations, accurate sendmail submission and exclusive spool claims.
4. **Protocol interoperability:** F06–F08. Upstream gRPC call-shape tests and independent DKIM vectors; real optional integration lanes.
5. **Replay policy:** F12. Retention/clock compatibility and distributed-store contract tests.
6. **Measurement/docs/release:** select I01–I07 only on evidence; document each behavior change; finalize compatibility decisions and run all gates on the candidate.

For a 2.1.1 backport, prioritize the verified security/data-loss corrections from these batches and other low-risk fixes with complete tests. Make a separate backport branch from the actual 2.1 commit; do not move an existing tag. A partial backport does not close the complete 2.2 plan.

## Verification and performance gates

### Local implementation workflow

- [ ] Record the current baseline, installed tooling and exact commands before changes.
- [ ] Run `composer ic:doctor`, `composer ic:list-config`, `composer ic:active-config`.
- [ ] Run focused failing probes, then regression tests, then `composer ic:process`; review all generated edits for scope and semantics.
- [ ] Run `composer ic:tests:details`, fix valid findings, then final `composer ic:release:guard` and required CI checks. Never disable a check to accommodate a protocol implementation.
- [ ] Keep regressions in ordinary host-test paths where prerequisites exist; separate optional live integration from pure/local doubles and identify unavailable prerequisites explicitly.
- [ ] Verify old v2 webhook tampering/downgrade and charset error-handler stack/mask regressions still pass.
- [ ] Build Sphinx with `sphinx-build -W --keep-going -b html docs build/docs` and review README/examples/release notes for final APIs.
- [ ] Verify production-only Composer installation and cold optional capability graphs.

### Meaningful performance evidence

Before changing hot paths, retain a baseline on the audited revision. Compare like-for-like PHP, extension/native versions, OPcache, OS/hardware, data and concurrency. Include successful output validation.

- CPU: typed/raw HTTP composition, authentication preparation and redaction, cookie lookup, v2 webhook sign/verify/claim, DKIM RSA/Ed25519 verify and multi-signature parsing.
- I/O: redirect chains, successful/truncated downloads, mixed fast/slow rolling pool, sendmail process control, IMAP targeted move, concurrent spool claims and native gRPC streaming.
- Scale: 1/10/25 MB payload paths, maximum parser input/depth/part counts and bounded repeated-worker runs.
- Record repeated medians, variance, successful RPM/RPS, p50/p95/p99, errors/timeouts, peak/steady memory, descriptors, active handles, child processes and queue growth. Establish workload-specific budgets from the baseline before optimization; do not assert a universal microsecond target.
- Run `composer ic:benchmark` for component evidence; use separate controlled harnesses for protocol I/O and representative host throughput. No live-network performance thresholds in ordinary unit tests.
- Accept resource-for-throughput tradeoffs only inside explicit capacity limits. Revert added abstraction when benefit is indistinguishable from noise; security fixes remain required even when additional checks cost time.

### Exact-candidate release gate

- [ ] All F01–F12 fixes and negative regressions pass; remaining follow-ups have explicit scope/status.
- [ ] Public API/configuration/wire changes are classified: patch correction, additive minor, or breaking major. Audit named arguments and real third-party consumers.
- [ ] PHP 8.4 and 8.5, prefer-lowest/prefer-stable, static/security, benchmark, clean install and docs CI are green on the **final committed revision**.
- [ ] Mailpit, minimal optional extensions and new native gRPC interoperability lanes pass with explicit prerequisites.
- [ ] Required process/filesystem/socket tests pass on supported operating systems; platform limitations are documented rather than inferred from Linux.
- [ ] Release notes include conditional risks, coordinated migrations, affected APIs and mitigations for security fixes. Decide coordinated disclosure before publishing exploit details or notifying third parties; no external notification is authorized by this plan.
- [ ] Freeze the candidate and tag exactly the verified commit. Any later source/docs/CI change requires fresh candidate validation.

## Completion definition

Implementation is complete only after the required finding groups are fixed and their acceptance tests pass. Release readiness additionally requires the exact-candidate gates above. Optional performance work may be deferred with evidence and a reason; confirmed trust-boundary and data-integrity defects must not be relabeled optional to close the release.

No major release is justified solely by the quantity of findings. Prefer targeted, compatible corrections. Revisit the version only when a concrete required public-contract change demonstrates that a minor release cannot represent the result honestly; use the [Semantic Versioning rules](https://semver.org/) for that decision.
