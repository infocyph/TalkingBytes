<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;

final readonly class EmailSenderFactory
{
    private Clock $clock;

    private EventDispatcher $events;

    private Sleeper $sleeper;

    public function __construct(
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
    ) {
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
        $this->sleeper = $sleeper ?? Sleeper::system();
    }

    public function fake(): Emailer
    {
        return Emailer::fake($this->events, $this->clock);
    }

    public function usingLog(LogEmailConfig $config): Emailer
    {
        return Emailer::usingLog($config, $this->events, $this->clock);
    }

    public function usingMailFunction(): Emailer
    {
        return Emailer::usingMailFunction($this->events, $this->clock);
    }

    public function usingNull(): Emailer
    {
        return Emailer::usingNull($this->events, $this->clock);
    }

    public function usingSendmail(
        SendmailConfig $config = new SendmailConfig(),
        ?CancellationSignal $cancellation = null,
    ): Emailer {
        return Emailer::usingSendmail(
            $config,
            $this->events,
            $this->clock,
            $cancellation,
            $this->sleeper,
        );
    }

    public function usingSmtp(SmtpConfig $config): Emailer
    {
        return Emailer::usingSmtp($config, $this->events, $this->clock);
    }

    public function usingSpool(SpoolConfig $config): Emailer
    {
        return Emailer::usingSpool($config, $this->events, $this->clock);
    }
}
