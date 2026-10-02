TalkingBytes 2.3 Hardening and Runwire Notes
============================================

Scope
-----

TalkingBytes 2.3 is a compatible minor release focused on transport security,
deadline/cancellation correctness, runtime-lifecycle isolation, and optional
Runwire 2.1 integration. Runwire remains a development/test dependency and a
production suggestion rather than a mandatory runtime requirement.

Security and correctness tightening
-----------------------------------

HTTP upload redirects
~~~~~~~~~~~~~~~~~~~~~

Redirects that convert an upload request to ``GET`` now discard every upload
source and transfer-body field together with stale body headers. This prevents a
303, or a supported 301/302 method conversion, from replaying bytes that no
longer belong to the redirected request. 307/308 replay semantics remain
unchanged, and caller-owned streams are not closed merely because a redirect
drops the body.

Signed uploads
~~~~~~~~~~~~~~

Signed file and stream uploads now bind the signature to the exact bounded byte
range prepared for transfer. Different upload bytes therefore produce different
signatures, offsets and sizes are honored, and non-repeatable or changed sources
fail closed instead of silently authenticating an empty-body hash.

This is an intentional security tightening. Applications or receivers that
mirrored the former empty-body convention for streamed/file uploads must update
their verification behavior together with TalkingBytes 2.3. The established
multipart ``UNSIGNED-PAYLOAD`` contract remains separate and is not silently
redefined.

MIME and resource limits
~~~~~~~~~~~~~~~~~~~~~~~~

Configured MIME header byte/count/line limits are applied to child MIME parts,
not only the root message. Nested oversized or excessive child headers are
rejected before they can bypass the finer parser policy.

HTTP response-header collection also supports package-owned aggregate budgets.
The default remains compatibility-oriented; applications processing untrusted
endpoints should select explicit bounded limits appropriate for their payloads.

Cancellation and deadlines
~~~~~~~~~~~~~~~~~~~~~~~~~~

Cancellation is checked independently of retry eligibility and before outbound
side effects on HTTP, email, webhook, mailbox, and gRPC paths that accept the
execution policy. Generated unary gRPC invocation now follows the same
preflight/cleanup expectations as its streaming siblings.

Total operation deadlines use monotonic absolute time across retries, redirects,
mailbox literal reads, protocol waits, and cleanup boundaries. An expired budget
cannot be rounded into a fresh retry attempt. SMTP, IMAP, POP3, sendmail, HTTP,
and gRPC paths clamp work to the earliest supported caller/host/protocol
deadline where the underlying operation exposes a timeout boundary.

Redirect and destination correctness
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Relative redirects use URL reference semantics without filesystem path
normalization. Empty path segments and trailing slashes are preserved, IPv6
literals are bracketed exactly once, and strict destination validation handles
public IPv6 literals and IPv4-mapped IPv6 consistently while retaining
fail-closed DNS/proxy/rebinding protections.

Cookie integrity
~~~~~~~~~~~~~~~~

Insecure origins cannot overwrite an existing Secure cookie for the same scope,
and ``__Secure-``/``__Host-`` prefix rules are enforced within the documented
cookie model. Domain-cookie sharing remains opt-in.

Execution collaborator propagation
----------------------------------

Resolved HTTP, email, and gRPC composition preserves injected clocks, sleepers,
cancellation signals, and total-operation deadlines through the decorators and
retry/resilience collaborators that use them. This keeps deterministic host
timing and request lifetime policy intact instead of rebuilding hidden default
collaborators.

Optional Runwire 2.1 integration
--------------------------------

The following composition surfaces accept explicit ``withRunwire()`` binding:

- ``HttpClientFactory``
- concurrent HTTP ``RequestPool``
- ``GrpcClientFactory``
- ``EmailSenderFactory``
- ``EmailMailboxFactory``
- ``EmailReceiverFactory``

A binding borrows the supplied ``RuntimeContext`` and, when supplied, the
current ``RequestContext`` and ``CoroutineScope``. TalkingBytes does not start or
stop the Runwire loop, complete a host request, close the host scope, install
signals, or manage host workers.

Bindings are immutable and request/task scoped. Reapplying the exact same
runtime/request/scope context is idempotent; attempting to rebind an already
bound graph to a different context is rejected so cancellation and deadline
state cannot leak between requests.

Supported cooperative waits
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

With a compatible active Runwire scope, TalkingBytes yields cooperatively for:

- retry and backoff sleeps
- concurrent cURL-multi driver waiting through bounded cooperative polling
- SMTP protocol reads/writes after connection establishment
- IMAP and POP3 protocol reads/writes after connection establishment
- IMAP IDLE/fallback polling delays
- sendmail process polling and pipe-backpressure waits

Normal bounded synchronous behavior remains the fallback when no compatible
scope is supplied.

Blocking limitations
~~~~~~~~~~~~~~~~~~~~

Runwire binding does not make these operations asynchronous:

- DNS resolution and TCP connection establishment
- SMTP/IMAP/POP3 TLS handshakes
- single-request ``curl_exec()`` transfer execution
- native gRPC channel/stub internals
- filesystem and spool I/O
- PHP ``mail()``
- process creation through ``proc_open()``

A timeout bound around a blocking call is not an asynchronous-I/O claim.

Architecture and reproducibility
--------------------------------

Runwire references are confined to the optional integration adapter and the
explicit protocol composition entry points. A repository-owned architecture
gate prevents accidental Runwire coupling in mandatory core/protocol classes and
checks that the adapter does not acquire host loop/request lifecycle ownership.

Resolved configuration parsing shared by HTTP/gRPC and email section handling is
centralized only where semantics are genuinely common; field-specific protocol
validation remains with its owner.

The release workflow pins the reviewed PHPForge workflow/tooling revision and
Mailpit image digest exercised by the candidate. Dedicated CI gates verify both
production installation without Runwire and integration behavior with Runwire
installed.

Compatibility and migration
---------------------------

- Composer's PHP requirement is ``^8.4`` and the README now states the same
  constraint.
- Runwire is optional in production. Applications that do not install or bind it
  retain the standalone path.
- Stream/file upload signatures intentionally change because they now bind the
  actual transferred bytes.
- Existing public protocol facades and normal synchronous transport selection
  remain available.
- Custom transports and explicit caller policy remain authoritative.

Performance and validation
--------------------------

The candidate benchmark suite includes unbound factory construction,
Runwire-bound construction, resolved bound construction, and the existing HTTP,
email, webhook, gRPC, resilience, and large-message component benchmarks.
Concurrent and socket/process integration tests additionally verify scheduler
peer progress on the paths documented as cooperative.

These repository benchmarks establish component regression and fairness
evidence; they do not claim universal production-application RPM. Sustained RPM,
p50/p95/p99 latency, downstream capacity, queue growth, and host memory must be
measured by an integrating application on its production-equivalent deployment
when those host-level numbers are required.
