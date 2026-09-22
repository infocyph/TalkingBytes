<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;

final readonly class EmailReceiverFactory
{
    private Clock $clock;

    private EventDispatcher $events;

    public function __construct(?EventDispatcher $events = null, ?Clock $clock = null)
    {
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
        );
    }
}
