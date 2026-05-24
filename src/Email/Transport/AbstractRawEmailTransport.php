<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;

abstract class AbstractRawEmailTransport
{
    public function __construct(
        protected RawEmailBuilder $rawEmailBuilder = new RawEmailBuilder(),
        protected EmailHeaderBuilder $headerBuilder = new EmailHeaderBuilder(),
    ) {}
}
