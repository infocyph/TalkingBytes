<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Runtime;

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use InvalidArgumentException;
use LogicException;

final readonly class RunwireBinding
{
    private bool $cooperativeScope;

    public function __construct(
        private RuntimeContext $runtime,
        private ?RequestContext $request = null,
        private ?CoroutineScope $scope = null,
    ) {
        if (PHP_INT_SIZE < 8) {
            throw new LogicException('Runwire integration requires 64-bit PHP.');
        }

        if ($request?->completed() === true) {
            throw new LogicException('Completed Runwire request context cannot be bound.');
        }

        if ($request !== null && $request->runtime() !== $runtime) {
            throw new LogicException('Runwire request context belongs to a different runtime context.');
        }

        $this->cooperativeScope = $scope !== null
            && $runtime->supports(RuntimeCapability::RUNWIRE_COROUTINES)
            && $runtime->supports(RuntimeCapability::RUNWIRE_LOOP_AVAILABLE);

        if ($scope !== null && !$this->cooperativeScope) {
            throw new InvalidArgumentException(
                'Runwire coroutine scope requires RUNWIRE_COROUTINES and RUNWIRE_LOOP_AVAILABLE capabilities.',
            );
        }
    }

    public function cancellation(?CancellationSignal $explicit = null): ?CancellationSignal
    {
        if ($explicit === null && $this->request === null && $this->scope === null) {
            return null;
        }

        return CancellationSignal::fromCallable(function () use ($explicit): bool {
            if ($explicit?->isRequested() === true) {
                return true;
            }

            if ($this->request?->completed() === true || $this->request?->cancelled() === true) {
                return true;
            }

            return $this->scope?->cancellation()->isCancelled() === true;
        });
    }

    public function deadline(?OperationDeadline $explicit = null): ?OperationDeadline
    {
        $deadline = $explicit;

        $requestDeadline = $this->request?->deadline()->monotonicNanoseconds;
        if ($requestDeadline !== null) {
            $candidate = OperationDeadline::at($requestDeadline / 1_000_000_000);
            $deadline = $deadline?->earliest($candidate) ?? $candidate;
        }

        $scopeDeadline = $this->scope?->cancellation()->deadline()->monotonicNanoseconds;
        if ($scopeDeadline !== null) {
            $candidate = OperationDeadline::at($scopeDeadline / 1_000_000_000);
            $deadline = $deadline?->earliest($candidate) ?? $candidate;
        }

        return $deadline;
    }

    public function runtime(): RuntimeContext
    {
        return $this->runtime;
    }

    public function sleeper(?Sleeper $explicit = null): Sleeper
    {
        if ($explicit !== null) {
            return $explicit;
        }

        if (!$this->cooperativeScope || $this->scope === null) {
            return Sleeper::system();
        }

        return new Sleeper(function (int $microseconds): void {
            if ($microseconds <= 0) {
                return;
            }

            $this->scope?->sleep($microseconds / 1_000_000);
        });
    }
}
