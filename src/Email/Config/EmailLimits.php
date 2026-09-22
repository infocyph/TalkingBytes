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

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            maxMessageBytes: ConfigValue::int($config, 'maxMessageBytes', 10_485_760),
            maxAttachmentBytes: ConfigValue::int($config, 'maxAttachmentBytes', 26_214_400),
            maxAttachmentCount: ConfigValue::int($config, 'maxAttachmentCount', 500),
            maxDecodedBodyBytes: ConfigValue::int($config, 'maxDecodedBodyBytes', 10_485_760),
            maxMimeDepth: ConfigValue::int($config, 'maxMimeDepth', 20),
            maxMimeParts: ConfigValue::int($config, 'maxMimeParts', 500),
            maxHeaderBytes: ConfigValue::int($config, 'maxHeaderBytes', 131_072),
            maxHeaderCount: ConfigValue::int($config, 'maxHeaderCount', 2_000),
            maxHeaderLineBytes: ConfigValue::int($config, 'maxHeaderLineBytes', 998),
        );
    }

    private function assertPositive(string $name, int $value): void
    {
        if ($value > 0) {
            return;
        }

        throw new InvalidArgumentException(sprintf('Email limit %s must be greater than zero.', $name));
    }
}
