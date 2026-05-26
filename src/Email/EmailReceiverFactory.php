<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;

final readonly class EmailReceiverFactory
{
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
        );
    }
}
