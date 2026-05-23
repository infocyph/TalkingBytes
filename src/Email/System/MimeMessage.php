<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use Infocyph\TalkingBytes\Email\Enum\ContentTransferEncoding;

final readonly class MimeMessage
{
    public function __construct(
        public string $contentType,
        public string $body,
        public ?ContentTransferEncoding $contentTransferEncoding = null,
    ) {}
}
