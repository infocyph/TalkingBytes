<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use InvalidArgumentException;

final readonly class LogEmailConfig
{
    public function __construct(
        public string $directory,
        public bool $dailyFiles = true,
        public string $filenamePrefix = 'email',
        public ?int $maxMessageBytes = null,
    ) {
        if (trim($this->directory) === '') {
            throw new InvalidArgumentException('Log directory is required.');
        }

        if (trim($this->filenamePrefix) === '') {
            throw new InvalidArgumentException('Log filename prefix is required.');
        }

        if ($this->maxMessageBytes !== null && $this->maxMessageBytes < 1) {
            throw new InvalidArgumentException('Log max message bytes must be greater than zero when provided.');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            directory: ConfigValue::string($config, 'directory', ''),
            dailyFiles: ConfigValue::bool($config, 'dailyFiles', true),
            filenamePrefix: ConfigValue::string($config, 'filenamePrefix', 'email'),
            maxMessageBytes: ConfigValue::nullableInt($config, 'maxMessageBytes'),
        );
    }
}
