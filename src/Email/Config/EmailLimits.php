<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use InvalidArgumentException;

final readonly class EmailLimits
{
    public function __construct(
        public int $maxMessageBytes = 10485760,
        public int $maxAttachmentBytes = 26214400,
        public int $maxAttachmentCount = 500,
        public int $maxDecodedBodyBytes = 10485760,
        public int $maxMimeDepth = 20,
        public int $maxMimeParts = 500,
        public int $maxHeaderBytes = 131072,
        public int $maxHeaderCount = 2000,
        public int $maxHeaderLineBytes = 998,
    ) {
        $this->assertPositive('maxMessageBytes', $this->maxMessageBytes);
        $this->assertPositive('maxAttachmentBytes', $this->maxAttachmentBytes);
        $this->assertPositive('maxAttachmentCount', $this->maxAttachmentCount);
        $this->assertPositive('maxDecodedBodyBytes', $this->maxDecodedBodyBytes);
        $this->assertPositive('maxMimeDepth', $this->maxMimeDepth);
        $this->assertPositive('maxMimeParts', $this->maxMimeParts);
        $this->assertPositive('maxHeaderBytes', $this->maxHeaderBytes);
        $this->assertPositive('maxHeaderCount', $this->maxHeaderCount);
        $this->assertPositive('maxHeaderLineBytes', $this->maxHeaderLineBytes);
    }

    private function assertPositive(string $name, int $value): void
    {
        if ($value > 0) {
            return;
        }

        throw new InvalidArgumentException(sprintf('Email limit %s must be greater than zero.', $name));
    }
}
