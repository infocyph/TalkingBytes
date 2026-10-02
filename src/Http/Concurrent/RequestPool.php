<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Concurrent;

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Integration\Runwire\RunwireBinding;

final readonly class RequestPool
{
    public function __construct(
        private CurlMultiTransport $transport,
        private int $maxConcurrency = 10,
        private bool $stopOnFailure = false,
        private ?CancellationSignal $cancellation = null,
        private ?OperationDeadline $operationDeadline = null,
        private ?RunwireBinding $runwireBinding = null,
    ) {}

    public function maxConcurrency(int $maxConcurrency): self
    {
        return new self(
            $this->transport,
            $maxConcurrency,
            $this->stopOnFailure,
            $this->cancellation,
            $this->operationDeadline,
            $this->runwireBinding,
        );
    }

    /**
     * @param array<int|string, HttpRequest> $requests
     */
    public function sendMany(array $requests): PoolResult
    {
        return $this->transport->sendMany(
            $requests,
            $this->maxConcurrency,
            $this->stopOnFailure,
            $this->cancellation,
            $this->operationDeadline,
            $this->runwireBinding,
        );
    }

    public function stopSchedulingOnFailure(bool $enabled = true): self
    {
        return new self(
            $this->transport,
            $this->maxConcurrency,
            $enabled,
            $this->cancellation,
            $this->operationDeadline,
            $this->runwireBinding,
        );
    }

    public function withCancellation(?CancellationSignal $cancellation): self
    {
        return new self(
            $this->transport,
            $this->maxConcurrency,
            $this->stopOnFailure,
            $cancellation,
            $this->operationDeadline,
            $this->runwireBinding,
        );
    }

    public function withOperationDeadline(OperationDeadline $deadline): self
    {
        $deadline = $this->operationDeadline?->earliest($deadline) ?? $deadline;

        return new self(
            $this->transport,
            $this->maxConcurrency,
            $this->stopOnFailure,
            $this->cancellation,
            $deadline,
            $this->runwireBinding,
        );
    }

    public function withRunwire(
        RuntimeContext $runtime,
        ?RequestContext $request = null,
        ?CoroutineScope $scope = null,
    ): self {
        if ($this->runwireBinding !== null) {
            $this->runwireBinding->assertSameContext($runtime, $request, $scope);

            return $this;
        }

        $binding = new RunwireBinding($runtime, $request, $scope);
        $deadline = $binding->deadline();
        if ($deadline !== null && $this->operationDeadline !== null) {
            $deadline = $this->operationDeadline->earliest($deadline);
        } elseif ($deadline === null) {
            $deadline = $this->operationDeadline;
        }

        $transport = $this->transport;
        $cooperativeSleeper = $binding->sleeper();
        if ($cooperativeSleeper !== null) {
            $transport = $transport->withCooperativeWait($cooperativeSleeper);
        }

        return new self(
            $transport,
            $this->maxConcurrency,
            $this->stopOnFailure,
            $binding->cancellation($this->cancellation),
            $deadline,
            $binding,
        );
    }
}
