# TalkingBytes 2.3.1 audit and release plan

Audit date: 2026-11-01 (Asia/Dhaka). Baseline: `1096b05c143db1167a69fb1ea78ab1ece99f4004` (the `2.3` tag). Status: R1/R2/R6/R8 remediation has passed hosted certification on `7eb3f84c`; subsequent R5/R7 safety regression coverage and documentation updates require the final HEAD matrix to be green. The original audit measurements below are historical and are **not** current blockers.

## Release-candidate verification and remaining sign-off

The [PR #18](https://github.com/infocyph/TalkingBytes/pull/18) is maintained on `patch/runwire-3`, targeting `main`; merge, tag and publication are reserved for manual review. The first fully green PR workflow is [run 41289626142](https://github.com/infocyph/TalkingBytes/actions/runs/41289626142) on `7eb3f84c13669daeafc0e9aa303ffb0d2a695164`. It includes **471 passing tests / 2,501 assertions**, zero PHPStan findings, passing PHP 8.4/8.5 stable and lowest dependency lanes, Pint, reference/duplicate/architecture checks, release acceptance, optional Runwire present/absent, native gRPC, Mailpit, documentation, clean installation, and sustained performance.

The same-runner benchmark at that SHA compared tag `2.2` (`ed7873c033498132a169b792e63f601aef98d1ca`) against unbound 2.3.1: c5 **-0.46%**, c20 **-1.41%**, c50 **-0.46%** relative RPM. All are within the unchanged **2%** budget; zero errors/timeouts. Bound Runwire throughput is characterized separately, not treated as a historical Runwire baseline. [Performance artifact from that run](https://github.com/infocyph/TalkingBytes/actions/runs/41289626142/artifacts/13076901215).

Changes made **after** that successful revision: file-identity verification on spool reads; no-clobber restoration of claimed messages; deterministic same-filesystem collision, cross-filesystem publication (when a distinct mounted filesystem is available), symlink-substitution, two-worker and crash-retention tests; and a second closed-scope Runwire regression. A new complete hosted matrix must certify the **exact final committed SHA** before this PR is marked ready for manual review. Earlier successful jobs alone cannot certify subsequent commits.

| Finding | Disposition |
| --- | --- |
| R1 — baseline 33 complexity failures | **Resolved** by cohesive refactoring; no rule/baseline changes; green PHPStan on `7eb3f84c` |
| R2 — Composer archive leakage | **Resolved** by tracked export exclusions and isolated archive consumers; clean-install job green |
| R3 — duplicate ownership | **Maintained at passing policy**; consolidated signed-upload ownership, native gRPC metadata, HTTP multi waits, IMAP response reading and spool publication; retain protocol-specific rules where merging would be misleading. Remaining structural similarity is non-blocking unless a functional defect is reproduced |
| R4 — exact SHA CI | **Green on `7eb3f84c`; final revision pending** |
| R5 — closed Runwire scope | **Documented host lifecycle contract** and explicit before-/after-binding failure regressions; no unsupported `isClosed()` API or silent blocking fallback |
| R6 — malformed HTTP policy metadata | **Resolved** by type/host validation and adversarial tests |
| R7 — spool collision/data integrity | **Remediated**, with exclusive publication, no-clobber restore, opened-file identity validation, and multi-process regression coverage; final matrix pending |
| R8 — audit provenance/branch QA | **Resolved**; historical plan restored and PR CI available |

Production-facing boundaries remain explicit: Runwire does not expose a public closed-scope admission predicate; a closed borrowed scope is detected at the next cooperative operation. Cross-filesystem durability and crash recovery depend on the deployment filesystem and operator policy: a crashed worker's claimed message remains for explicit recovery. Linux advisory locks assume all cooperating consumers use the library and the spool directory is trusted.

## Original audit: findings, engineering boundaries, and provenance

## Decision and engineering constraints

Prepare **2.3.1** for the Runwire 3.0 compatibility update, package hygiene and compatible corrective maintenance. No new public capability or intentional breaking change is currently needed. Reconsider a minor release only if the agreed implementation adds public functionality.

Follow [PHPForge engineering principles](../vendor/infocyph/phpforge/resources/engineering-principles.md) and [its agent workflow](../vendor/infocyph/phpforge/resources/AGENTS.md). Preserve public signatures, named arguments, protocol outcomes, caller policy and resource ownership. Keep Runwire optional and bind only host-supplied contexts. Simplify existing owners before extracting types. Never raise complexity limits, add suppressions/baselines, exclude offending source, weaken assertions or relax the 2% RPM budget to obtain green checks.

This is the current release tracker. The [2.3 plan](talkingbytes-2.3-audit-and-runwire.md) describes historical implementation and certification, not acceptance of this working tree.

## Audit scope and baseline evidence

- Clean starting working tree; 301 tracked source files and 64 test/fixture files. Configured syntax/reference checks scanned 373 PHP files; duplicate/comment analysis scanned 310 files.
- Reviewed dependency/runtime declarations, optional integration ownership, HTTP DNS pinning/proxy/redirect/cookie/upload/download boundaries, webhook authentication/replay/limits, email TLS/command/parser/DKIM/spool/process boundaries, gRPC extension/transport contracts, mutable-state lifetime, observability, benchmarks, documentation, packaging and workflow coverage. Graphify was navigation only; findings were checked against source and tool output.
- Host: PHP 8.5.4, Composer 2.10.3. Native gRPC and IMAP extensions absent. The dedicated Mailpit and native-gRPC jobs register tests only when explicitly enabled; the ordinary suite does not certify them.
- `composer ic:doctor`, `ic:list-config`, `ic:active-config`: healthy; all 11 configurations resolve from installed PHPForge `336bd3d598d5563eb67011075f211738b7bcef5c`.
- Baseline `composer ic:tests:details`: exit 2. Syntax, references, comments, normalization, Pint, PHPCS, Deptrac and Psalm security analysis pass. PHPStan reports **33 cognitive-complexity findings**. Rector requests one null-safe metadata simplification in `WebhookSender::cancelledResult()`.
- Restricted baseline Pest reported 12 failures, 43 skips and 413 passes. Repeating `composer ic:test:code` with host socket access passed **468 tests / 2,482 assertions**, including stream-readiness and socket-write-deadline cases. These restricted failures are environment evidence, not reproduced product defects.
- Changing only the Runwire constraint from `^2.1` to `^3.0` installed published **3.0** in place of 2.1.2, with no other package changes. Composer reported **zero advisories** and the existing abandoned development dependency `doctrine/annotations` (through PHPBench). Repeat host suite with 3.0: **468 tests / 2,482 assertions**, no reported skips. No adapter source change is required by this evidence.
- No new exploitable vulnerability was reproduced in this audit. This is bounded source/tool/test evidence, not a claim that the library is vulnerability-free.
- Duplicate detector passes its current policy but reports **18 clone groups / 925 lines / 2.83%**. Those groups require semantic triage; a passing detector does not erase the maintenance concern.
- Deptrac passes with **38 uncovered** dependencies. Its generic Project/Vendor model is not protocol-module isolation proof. Existing Runwire architecture tests separately constrain integration references and host ownership.

## Findings and required work

### R1 — Initial static-analysis gate failed (remediated in PR #18)

The active PHPStan extension measures cognitive complexity with class maximum 80 and function maximum 12. These are existing baseline violations, not Runwire 3.0 API/type errors. Fix the underlying branching/cohesion while preserving behavior. Keep classes below the aggregate limit; moving branches between private methods alone does not reduce class complexity.

| Owner | Findings (current complexity) |
| --- | --- |
| `performance/http/compare.php` | `run` 16 |
| `Core/Support/RetryExecutor` | `run` 15 |
| `Email/Dkim/DnsDkimPublicKeyResolver` | `resolve` 16 |
| `Email/Mailbox/FakeMailboxTransport` | `search` 14 |
| `Email/Mailbox/ImapEnvelopeParser` | `parse` 15; `splitTopLevel` 14 |
| `Email/Mailbox/ImapFetchLiteralMapper` | `firstFetchLiteral` 13 |
| `Email/Mailbox/ImapResponseParser` | `status` 15; `parseFetchMeta` 13 |
| `Email/Mailbox/ImapSocketTransport` | class 88 |
| `Email/Parser/BounceParser` | `parseMany` 14 |
| `Email/Parser/DeliveryStatusParser` | `parseFieldBlock` 13 |
| `Email/Parser/MimePartParser` | `splitMultipartBody` 13 |
| `Email/Parser/TransferDecoder` | `decodeBase64` 15 |
| `Email/Receiver/SpoolEmailReceiver` | `firstSpoolFile` 18 |
| `Email/System/RawEmailBuilder` | `buildToStream` 15 |
| `Email/System/SmtpIoRuntime` | `write` 14 |
| `Grpc/GrpcMetadata` | constructor 15 |
| `Grpc/Middleware/RetryMiddleware` | `handle` 14 |
| `Grpc/Native/GeneratedStubGrpcInvoker` | class 91 |
| `Http/Body/MultipartBody` | `prepareCurlPayload` 13; `spoolStreamPart` 14 |
| `Http/Concurrent/CurlMultiTransport` | class 85; `sendMany` 18; `waitForActivity` 14 |
| `Http/Cookie/CookieJar` | class 82 |
| `Http/HttpClient` | `applyCoreOptionDefaults` 14 |
| `Http/HttpClientConfig` | `parseDefaultHeaders` 15 |
| `Http/HttpRequest` | class 97 |
| `Http/Internal/RedirectResolver` | `removeDotSegments` 16 |
| `Http/Stats/HttpTransferStats` | `fromCurlInfo` 15 |
| `Http/Transport/CurlTransport` | `send` 21 |
| `Webhook/WebhookSender` | `send` 16 |

Implement by coherent batches: retry/webhook; HTTP requests/configuration/redirects/cookies/pools; email parsing/mailbox/spool/SMTP; gRPC; performance comparator. Before each semantic change, run the affected regression tests and capture representative baseline measurements for hot-path changes. Add adversarial coverage only for an uncovered contract. Run PHPStan after each batch, then the complete required QA flow after the final implementation.

### R2 — Development/generated material leaks into Composer archives

The initial actual Composer archive contains **733 entries**, including ignored `graphify-out` history/cache/graphs and `plans/talkingbytes-2.3-audit-and-runwire.md`. Git ignore rules do not protect Composer archives.

Add explicit export exclusions for `graphify-out`, PHPForge knowledge output, build/var output and plans; rebuild the archive, compare every tracked `src/` file with the package, and install an isolated production consumer from that archive. Check that factories, protocol fakes and ordinary operations load without Runwire or PHPForge. Also check a separate consumer with published Runwire 3.0. Archive acceptance is separate from source-checkout tests.

**Completed locally:** exclusions also remove the empty local `.aws` directory. The rebuilt archive has **307 entries**, down from 733, with all **301 tracked source files** present. Fresh-process consumer smoke passes for HTTP, email, gRPC, webhook signing/verification and all optional-integration factory constructors without Runwire/PHPForge. Installing published Runwire 3.0 into the isolated consumer also passes bound cancellation and confirms the host request is not completed by TalkingBytes. The consumer uses the archive's actual Composer metadata and runs outside the source checkout. Temporary consumer version `2.3.1` is test metadata, not a tag or published release.

### R3 — Duplicate ownership requires targeted consolidation

Triage all 18 reported clone groups. Prioritize repeated Runwire binding policy across six factories/pools, pool execution/cleanup paths, multipart/attachment/upload stream checks, repeated configuration decoding, and mailbox read/deadline paths. Centralize equivalent logic in an existing cohesive owner and update affected callers together. Preserve independently meaningful protocol rules, public exceptions, stream offsets and cancellation/resource semantics; do not merge unrelated validators just because token structure matches. Import/declaration-only matches should be recorded as structural similarity, not used to justify artificial abstractions. Do not suppress groups or add a generic helper layer solely to improve a duplication ratio.

### R4 — Final revision needs independent release certification (initial audit finding)

Any earlier 2.3 green run validates only its own commit. The changed working tree has no exact-final-SHA CI evidence yet. Complete PHP 8.4/8.5, normal/lowest dependency resolution, clean production installation, Runwire present/absent, optional-extension coldness, Mailpit, native gRPC, documentation and sustained-performance jobs on the final committed candidate. Keep merge/tag/publication user-owned.

Live verification of the baseline's [scheduled run 41272555610](https://github.com/infocyph/TalkingBytes/actions/runs/41272555610) found failures in release acceptance, PHP 8.4/8.5 analysis and all four normal/lowest QA lanes. Release-acceptance logs reproduce the same 33 complexity findings and Rector edit; inspected PHP 8.4 lowest logs fail on that Rector edit. Sustained HTTP performance, both benchmark jobs, Mailpit, native gRPC, documentation, optional-capability coldness, clean production install and Runwire present/absent jobs passed. The current committed revision therefore has a QA block; the older recorded c50 failure is not the current performance status. These results still do not certify the local Runwire 3.0 changes.

### R5 — Borrowed Runwire scope lifecycle must fail predictably (P1; reproduction required)

**Source review:** `src/Integration/Runwire/RunwireBinding.php` checks runtime capability, request completion and request/runtime identity, but does not establish that a supplied `CoroutineScope` is still open. Current Runwire 3.0 `CoroutineScope` marks itself closed and its `sleep()`, `waitReadable()`, `waitWritable()` and `yieldNow()` operations reject closed scopes with `LogicException`. TalkingBytes' cooperative sleeper and readiness bridge handle `CancelledException`, not this separate lifecycle failure. Runwire does not currently expose a public `isClosed()` method. This is a **source-established contract gap, not a reproduced production incident**.

Add real Runwire 3.0 tests for (a) closing the host scope before construction/binding where possible, (b) closing it after graph construction and before an HTTP/email/mailbox/gRPC/webhook operation, and (c) closing it during cooperative retry/read/write waits. Establish the expected failure result and confirm no subsequent protocol side effects. Do not use reflection, rely on private Runwire state, invent an implicit runtime or silently switch to blocking I/O. If the required admission check is impossible through Runwire's public API, document the upstream dependency and make active-operation failures deterministic and safe at the existing boundary. Preserve host-owned scope/request lifecycle.

### R6 — Reject malformed HTTP security policy metadata (P1; adversarial test first)

**Source review:** `src/Http/Internal/RequestSecurityGuard.php::normalizeHosts()` maps a non-array `security_allow_hosts` or `security_block_hosts` value to `[]`, and silently discards invalid list entries. Because an empty allow-list means unrestricted hosts, malformed caller-provided metadata can silently disable intended restrictions, even though the public `allowHosts()/blockHosts()` fluent methods themselves accept arrays. The private-network toggle similarly activates only for literal `true`. This is a **fail-open configuration risk**, not evidence of remote exploitation.

Add tests for wrong types, mixed lists, empty lists, normalized host forms and conflicting policies supplied through `HttpRequest::metadata()`. Validate explicitly supplied security metadata at the request/security boundary and reject malformed values instead of converting them into an absent policy. Preserve documented intentional `[]` semantics, host normalization, DNS pinning, HTTP proxy rejection and redirect-by-redirect revalidation. Verify both single and multi transports.

### R7 — Make spool claim and destination publication collision-safe (P0; data-integrity risk)

**Source review:** `src/Email/Receiver/SpoolEmailReceiver.php::uniqueTarget()` checks `file_exists()` before returning a destination and `moveFileToDirectory()` subsequently calls `rename()`. A separate writer or worker can create that target between check and rename; on platforms where rename replaces existing destinations, a concurrent operation can overwrite another message. This path is used for processing, successful moves and failure quarantine. No concurrent-overwrite reproduction was run in this review.

Create deterministic multi-process and collision-injection regressions for two workers targeting the same basename, destination pre-existence, same-filesystem and cross-filesystem behavior, interrupted moves, and failure-quarantine sidecars. Implement exclusive, no-clobber destination reservation/publication with correct cleanup and recovery semantics. Preserve atomic consumption where available; do not replace an existing message or silently lose a claimed item. Also evaluate the `firstSpoolFile()` check-to-open race and verify a file chosen as a regular non-symlink remains safe when opened and bounded for reading (condition protection on the documented spool-directory trust model). Retain ownership and file-permission controls.

### R8 — Preserve audit provenance and make branch QA discoverable (P1 process/maintenance)

The branch deletes `plans/talkingbytes-2.3-audit-and-runwire.md` but this 2.3.1 tracker still links to it as the historical audit. Restore the historical document or replace the reference with a durable equivalent; simply deleting it leaves misleading audit provenance. `.gitattributes` already excludes `plans` from Composer export, so preservation does not reopen R2.

The sole local workflow runs on pushes to `main`/`master`, tags and qualifying pull requests, **not** arbitrary `patch/*` pushes. There is currently no hosted run for the reviewed branch HEAD `a0500613495e769444853d8639b951342e5816e6`. Use a PR-based candidate check (or an explicitly authorized equivalent), inspect every matrix lane on the exact final revision, and do not confuse the previously green 2.3 baseline with 2.3.1 certification. Release acceptance should confirm the matched package archive and final commit, not merely a successful local checkout.

## Remediation order and required proofs

1. **Data integrity and policy first:** reproduce and resolve R7, R6 and the R5 lifecycle edge cases; add targeted regression tests without broadening public APIs.
2. **Existing engineering gate:** resolve R1's 33 findings by cohesive changes, triage R3's 18 clone groups; do not game class/function complexity, duplicate detectors or change detector thresholds.
3. **Parity and packaging:** rerun HTTP/SMTP/IMAP/POP3/gRPC/webhook tests, Runwire absent/present, normal/lowest dependency resolution, no-dev archive consumers, and R8 provenance checks.
4. **Performance and longevity:** retain the 2% matched sustained RPM acceptance; compare c5/c20/c50 with ordered/reversed repeat trials, then repeat graph lifecycles and worker soaks.
5. **Final acceptance:** commit the final candidate, execute every required hosted workflow on that exact SHA, save evidence and leave merge/tag/publication to the maintainer.

The new source findings above are **review observations requiring tests**, not claims that failing regressions have already been reproduced or remediated. No source changes beyond the pre-existing branch commit are implied.

## Execution and acceptance checklist

- [x] Record clean baseline, active toolchain, full host test inventory and current QA failures.
- [x] Verify published Runwire 3.0, install `^3.0`, update supported integration documentation/suggestion, and rerun the full host suite.
- [x] Run required `composer ic:process`; review its complete diff, including the existing Rector finding. Host execution passes; only `WebhookSender::cancelledResult()` changes from a null-check ternary to null coalescing. Restricted Rector initially could not bind its worker TCP server.
- [x] Verify archive exclusions, complete runtime source parity, isolated `--no-dev` consumers, and optional Runwire 3.0 composition.
- [x] Implement R7 no-clobber spool publication and restoration; add deterministic file-replacement, symlink, concurrent-worker and crash-retention regressions (final CI pending).
- [x] Resolve R6 fail-open HTTP policy metadata and cover R5 closed borrowed Runwire scopes without unsupported lifecycle introspection (final CI pending).
- [x] Restore historical 2.3 audit, open PR #18, and verify a complete hosted run on the committed branch.
- [x] Resolve R1 at the original PHPForge limits; reduce shared ownership hotspots without merging semantically distinct protocol validators. Duplicate detector remains green.
- [x] Obtain passing hosted release-acceptance/QA and static analysis on `7eb3f84c`; rerun all checks after the final safety/documentation commit.
- [x] Build docs using the pinned requirements and warnings as errors. `UV_CACHE_DIR=/tmp/tb-uv-cache uv run --with-requirements docs/requirements.txt sphinx-build -W --keep-going -b html docs /tmp/tb-docs` passes.
- [x] Run component benchmark smoke: `composer ic:bench:quick` passes **38 subjects, zero failures/errors**. This is not proof of sustained application RPM.
- [x] Pass same-runner c5/c20/c50 against tag `2.2` on `7eb3f84c` with the unchanged 2% budget and retained CI artifact; repeat for the exact final commit.
- [x] Verify repeated graph/request cleanup and worker-oriented regressions in the hosted suite; longer deployment soaks remain the integrator's responsibility, not a claim of CI certification.
- [ ] Finalize release notes, run the complete hosted matrix on the **last** SHA, attach checks/artifacts to PR #18, mark ready for manual review; do not merge/tag/publish.

## Optional improvements, separate from required fixes

- Consider protocol-module architecture rules in addition to the existing optional-Runwire tests, after defining permitted edges from actual public contracts.
- Review redundant execution of full QA in the `release-acceptance` workflow job if hosted duration is material; retain every distinct detector and integration gate.
- Remove the abandoned PHPBench transitive dependency centrally in PHPForge when its supported upstream dependency graph permits it. Do not add a consumer-local workaround or call abandonment an advisory.
- Defer general class/file consolidation, new PSR adapters, fully asynchronous protocol rewrites and speculative tuning. No measured need was established for them here.

## Evidence locations and limitations

Local logs are temporary: `/tmp/tb-baseline-details.log`, `/tmp/tb-baseline-host-code.log`, `/tmp/tb-runwire-update.log`, `/tmp/tb-runwire3-host-code.log`, `/tmp/tb-baseline-phpstan.log`, `/tmp/tb-archive-before-files.log`, `/tmp/tb-final-details.log`, `/tmp/tb-final-guard.log`, `/tmp/tb-process-host.log`, `/tmp/tb-bench-quick.log`, `/tmp/tb-docs.log`, and `/tmp/tb-consumer-bound-install.log`. Retain equivalent CI artifacts for release review; do not depend on `/tmp` as durable release evidence.

### Historical local verification at the start of the audit

| Check | Outcome |
| --- | --- |
| Strict Composer validation and normalization | Pass. Runwire 3.0 is installed; only that dependency was updated in the ignored local lock file. |
| `composer ic:process` with host socket access | Pass; one reviewed, behavior-preserving Rector source edit. |
| `composer ic:tests:details` with host socket access | Exit **1** solely for the same **33 PHPStan complexity findings**. **468 tests / 2,482 assertions** pass; syntax, references, duplicate policy, comments, Pint, PHPCS, Deptrac, Psalm security analysis and Rector pass. |
| `composer ic:release:guard` with host/network access | Exit **1** at PHPStan, reproducing the same 33 findings. Validation, runtime constraints, online audit, preceding checks and the full test suite pass. Later detectors are covered by the separate detailed run, not claimed as executed after the guard's failure. |
| Composer security audit | Zero advisories; the existing abandoned development package remains a maintenance item. |
| Documentation and quick benchmarks | Warning-free build; 38 benchmark subjects, zero failures/errors. |
| Actual Composer archive and isolated consumers | Pass; 307 entries, all 301 runtime source files, standalone and Runwire 3.0-bound smoke checks pass. Archive SHA-256: `3b5a000b0b71487d1b1d0bcf466ae45043e076de7dfb20f81fcf240e7cd43fd7`. |
| Git whitespace validation | `git diff --check` passes. |
| Final-candidate hosted matrix / sustained throughput / long soak | Open. No final candidate was committed, pushed, tagged or published by this audit. Host PHP 8.4 is unavailable; native-gRPC/Mailpit/minimal-extension evidence described above belongs to the baseline hosted run. |

The preceding evidence describes the **initial audit before PR #18 implementation**. Refer to the release-candidate verification section at the top for the current remediation and final-SHA acceptance state; no release has been tagged, merged or published.
