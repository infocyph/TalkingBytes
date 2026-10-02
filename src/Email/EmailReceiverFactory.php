<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;
use Infocyph\TalkingBytes\Integration\Runwire\RunwireBinding;

final readonly class EmailReceiverFactory
{
    private Clock $clock;

    private EventDispatcher $events;

    public function __construct(
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        private ?CancellationSignal $cancellation = null,
        private ?OperationDeadline $operationDeadline = null,
    ) {
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
    }

    public function usingSpool(
        SpoolConfig $config,
        ?EmailParser $parser = null,
        bool $deleteAfterRead = false,
        ?string $moveAfterRead = null,
        ?string $failedDirectory = null,
    ): SpoolEmailReceiver {
        return new SpoolEmailReceiver(
            $config,
            $parser ?? new RawEmailParser(),
            $deleteAfterRead,
            $moveAfterRead,
            $failedDirectory,
            $this->events,
            $this->clock,
            $this->cancellation,
            $this->operationDeadline,
        );
    }

    public function withRunwire(
        RuntimeContext $runtime,
        ?RequestContext $request = null,
        ?CoroutineScope $scope = null,
    ): self {
        $binding = new RunwireBinding($runtime, $request, $scope);
        $deadline = $binding->deadline();
        if ($deadline !== null && $this->operationDeadline !== null) {
            $deadline = $this->operationDeadline->earliest($deadline);
        } elseif ($deadline === null) {
            $deadline = $this->operationDeadline;
        }

        return new self(
            $this->events,
            $this->clock,
            $binding->cancellation($this->cancellation),
            $deadline,
        );
    }
}
