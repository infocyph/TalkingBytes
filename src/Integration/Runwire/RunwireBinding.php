<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Integration\Runwire;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Core\Support\StreamWaiter;
use LogicException;
use RuntimeException;

final readonly class RunwireBinding
{
    public function __construct(
        private RuntimeContext $runtime,
        private ?RequestContext $request = null,
        private ?CoroutineScope $scope = null,
    ) {
        if (PHP_INT_SIZE < 8) {
            throw new RuntimeException('Runwire integration requires 64-bit PHP.');
        }

        if ($this->request !== null) {
            if ($this->request->completed()) {
                throw new LogicException('Completed Runwire request context cannot be bound.');
            }

            if ($this->request->runtime() !== $this->runtime) {
                throw new LogicException('Runwire request context belongs to a different runtime context.');
            }
        }

        if ($this->scope !== null && !$this->supportsCooperativeScope()) {
            throw new LogicException('Runwire coroutine scope requires coroutine and loop capabilities.');
        }
    }

    public function cancellation(?CancellationSignal $explicit = null): ?CancellationSignal
    {
        if ($explicit === null && $this->request === null && $this->scope === null) {
            return null;
        }

        $request = $this->request;
        $scope = $this->scope;

        return CancellationSignal::fromCallable(
            static function () use ($explicit, $request, $scope): bool {
                if ($explicit?->isRequested() === true || $request?->completed() === true) {
                    return true;
                }

                if ($request !== null && self::tokenCancelledOutsideDeadline($request->cancellation)) {
                    return true;
                }

                return $scope !== null
                    && self::tokenCancelledOutsideDeadline($scope->cancellation());
            },
        );
    }

    public function deadline(): ?OperationDeadline
    {
        $nanoseconds = [];

        $requestDeadline = $this->request?->deadline()->monotonicNanoseconds;
        if ($requestDeadline !== null) {
            $nanoseconds[] = $requestDeadline;
        }

        $scopeDeadline = $this->scope?->cancellation()->deadline()->monotonicNanoseconds;
        if ($scopeDeadline !== null) {
            $nanoseconds[] = $scopeDeadline;
        }

        if ($nanoseconds === []) {
            return null;
        }

        return OperationDeadline::at(min($nanoseconds) / 1_000_000_000);
    }

    public function sleeper(?Sleeper $fallback = null): ?Sleeper
    {
        if ($this->scope === null) {
            return $fallback;
        }

        $scope = $this->scope;

        return new Sleeper(
            static function (int $microseconds) use ($scope): void {
                try {
                    $scope->sleep($microseconds / 1_000_000);
                } catch (CancelledException) {
                    // The composed cancellation signal reports the host cancellation
                    // to RetryExecutor immediately after this cooperative wait.
                }
            },
        );
    }

    public function streamWaiter(): ?StreamWaiter
    {
        if ($this->scope === null) {
            return null;
        }

        $scope = $this->scope;

        return new StreamWaiter(
            static fn(mixed $stream, ?OperationDeadline $deadline): bool => self::waitForStream(
                $scope,
                $stream,
                $deadline,
                readable: true,
            ),
            static fn(mixed $stream, ?OperationDeadline $deadline): bool => self::waitForStream(
                $scope,
                $stream,
                $deadline,
                readable: false,
            ),
        );
    }

    private static function tokenCancelledOutsideDeadline(CancellationToken $token): bool
    {
        if (!$token->isCancelled()) {
            return false;
        }

        return $token->reason() !== CancellationReason::DEADLINE_EXCEEDED;
    }

    /**
     * @param resource $stream
     */
    private static function waitForStream(
        CoroutineScope $scope,
        mixed $stream,
        ?OperationDeadline $deadline,
        bool $readable,
    ): bool {
        try {
            if ($deadline === null) {
                $readable ? $scope->waitReadable($stream) : $scope->waitWritable($stream);

                return true;
            }

            $remaining = $deadline->remainingSeconds();
            if ($remaining <= 0.0) {
                return false;
            }

            $bounded = RequestDeadline::afterSeconds($remaining, hrtime(true));
            $scope->withDeadline(
                $bounded,
                static function (CoroutineScope $waitScope) use ($stream, $readable): void {
                    $readable ? $waitScope->waitReadable($stream) : $waitScope->waitWritable($stream);
                },
            );

            return true;
        } catch (CancelledException) {
            return false;
        }
    }

    private function supportsCooperativeScope(): bool
    {
        return $this->runtime->supports(RuntimeCapability::RUNWIRE_COROUTINES)
            && $this->runtime->supports(RuntimeCapability::RUNWIRE_LOOP_AVAILABLE);
    }
}
