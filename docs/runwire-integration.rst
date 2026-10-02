Runwire runtime integration
===========================

TalkingBytes can optionally borrow a Runwire 2.1 runtime context without
making Runwire a production requirement for unbound applications.

Binding model
-------------

The integration is explicit. A host passes its existing ``RuntimeContext`` and,
when available, its current ``RequestContext`` and ``CoroutineScope`` through a
``withRunwire()`` method. TalkingBytes does not discover a global runtime,
create an event loop, complete a request, close a scope, install signal
handlers, or manage worker lifecycle.

The binding is available on:

- ``HttpClientFactory``
- ``GrpcClientFactory``
- ``EmailSenderFactory``
- ``EmailMailboxFactory``
- ``EmailReceiverFactory``
- concurrent HTTP ``RequestPool`` instances returned by ``HttpClient::multi()``

A runtime-only binding is valid and keeps the normal synchronous protocol path.
Adding a request context propagates its cancellation and absolute deadline.
Adding a compatible coroutine scope also enables the cooperative waits listed
below.

Bindings are immutable. Intermediaries may reuse the same host context when
composing a factory, and separately bound request graphs do not share
cancellation state. Host-owned request and scope objects remain usable after a
TalkingBytes operation returns.

Example
-------

A host can bind the same request lifecycle to multiple protocol graphs::

    $http = (new HttpClientFactory())
        ->withRunwire($runtime, $request, $scope)
        ->fromConfig($httpConfig);

    $mailer = (new EmailSenderFactory())
        ->withRunwire($runtime, $request, $scope)
        ->usingSmtp($smtpConfig);

    $mailbox = (new EmailMailboxFactory())
        ->withRunwire($runtime, $request, $scope)
        ->usingImap($imapConfig);

For concurrent HTTP, bind the pool itself::

    $pool = HttpClient::multi(maxConcurrency: 16)
        ->withRunwire($runtime, $request, $scope);

    $results = $pool->sendMany($requests);

Capability and lifetime rules
-----------------------------

TalkingBytes accepts a borrowed coroutine scope only when the supplied runtime
advertises both Runwire coroutine and loop capabilities. A completed request,
a request from another runtime, or an incompatible scope is rejected at the
binding boundary.

Cancellation and deadlines remain live after binding. An explicit
TalkingBytes cancellation signal is composed with host cancellation rather
than replaced. Operation deadlines use the earliest caller, request, scope, or
protocol deadline and are never renewed by retries or repeated protocol
commands.

A Runwire deadline cancellation is represented through the deadline boundary,
not duplicated as ordinary cancellation. This keeps timeout and cancellation
outcomes distinguishable.

Cooperative waits
-----------------

When a compatible ``CoroutineScope`` is bound, TalkingBytes currently yields to
the borrowed Runwire scheduler for these waits:

- retry and backoff sleeps across supported protocol retry paths
- cURL-multi driver waiting in concurrent HTTP pools; the driver uses bounded
  cooperative polling and does not claim socket-watcher ownership
- SMTP protocol socket reads and writes after the connection is established
- IMAP and POP3 protocol socket reads and writes after the connection is
  established
- IMAP IDLE/fallback polling delays
- sendmail nonblocking process polling and pipe-backpressure waits

These paths retain their normal synchronous fallback when no compatible scope
is bound. Cooperative waiting does not imply that the entire protocol stack is
asynchronous.

Operations that remain blocking
-------------------------------

The following work remains blocking in 2.3 even when Runwire is bound:

- DNS resolution and TCP connection establishment
- TLS handshakes for SMTP, IMAP, and POP3
- single-request cURL transfer execution
- native gRPC channel/stub internals
- filesystem and spool I/O
- PHP ``mail()``
- process creation through ``proc_open()``

Where TalkingBytes controls a timeout around these operations, the earliest
host/protocol deadline is used as the bound. A bounded blocking operation is
still blocking; it must not be described as cooperative or fully asynchronous.

Ownership and cleanup
---------------------

TalkingBytes borrows Runwire resources. It never stops the Runwire loop,
completes a ``RequestContext``, closes the caller's ``CoroutineScope``, or
changes host worker/signal policy.

Protocol resources remain owned by their TalkingBytes graph:

- cURL handles are removed and closed by their transport
- SMTP/IMAP/POP3 sockets are closed by their protocol lifecycle
- sendmail child processes and pipes are terminated/closed by process
  supervision
- native gRPC resources retain their existing caller-defined lifetime

Custom transports and explicit caller policy remain authoritative. The optional
Runwire adapter only supplies cancellation, deadlines, cooperative sleeping,
and bounded stream-readiness behavior when those collaborators were not
already replaced by stronger caller policy.
