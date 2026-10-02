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
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;
use Infocyph\TalkingBytes\Integration\Runwire\RunwireBinding;

final readonly class EmailMailboxFactory
{
    private Clock $clock;

    private EventDispatcher $events;

    private Sleeper $sleeper;

    public function __construct(
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
        private ?CancellationSignal $cancellation = null,
        private ?OperationDeadline $operationDeadline = null,
    ) {
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
        $this->sleeper = $sleeper ?? Sleeper::system();
    }

    public function usingImap(ImapConfig $config): Mailbox
    {
        return Mailbox::usingImap(
            $config,
            $this->events,
            $this->clock,
            $this->sleeper,
            $this->cancellation,
            $this->operationDeadline,
        );
    }

    public function usingPop3(Pop3Config $config): Pop3Mailbox
    {
        return Pop3Mailbox::usingConfig(
            $config,
            $this->events,
            $this->clock,
            $this->sleeper,
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
            $binding->sleeper($this->sleeper),
            $binding->cancellation($this->cancellation),
            $deadline,
        );
    }

}
