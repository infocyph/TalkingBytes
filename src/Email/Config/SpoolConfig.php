<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use InvalidArgumentException;

final readonly class SpoolConfig
{
    public function __construct(
        public string $directory,
        public bool $writeMetadata = true,
        public ?string $processingDirectory = null,
        public string $extension = 'eml',
        public bool $lockBeforeRead = false,
        public int $maxMessages = 20,
        public ?int $olderThanSeconds = null,
        public ?int $newerThanSeconds = null,
        public ?int $maxMessageBytes = null,
    ) {
        if (trim($this->directory) === '') {
            throw new InvalidArgumentException('Spool directory is required.');
        }

        if (trim($this->extension) === '') {
            throw new InvalidArgumentException('Spool extension is required.');
        }
        if (preg_match('/^[A-Za-z0-9]{1,16}$/D', $this->extension) !== 1) {
            throw new InvalidArgumentException('Spool extension must contain 1-16 letters or digits.');
        }

        $directories = array_filter([$this->directory, $this->processingDirectory], is_string(...));
        $normalizedDirectories = array_map(
            static fn(string $path): string => rtrim(str_replace('\\', '/', $path), '/'),
            $directories,
        );
        if (count($normalizedDirectories) !== count(array_unique($normalizedDirectories))) {
            throw new InvalidArgumentException('Spool source and processing directories must not overlap.');
        }

        if ($this->maxMessages < 1) {
            throw new InvalidArgumentException('Spool max messages must be greater than zero.');
        }

        if ($this->olderThanSeconds !== null && $this->olderThanSeconds < 0) {
            throw new InvalidArgumentException('Spool older-than filter must be zero or greater.');
        }

        if ($this->newerThanSeconds !== null && $this->newerThanSeconds < 0) {
            throw new InvalidArgumentException('Spool newer-than filter must be zero or greater.');
        }

        if ($this->maxMessageBytes !== null && $this->maxMessageBytes < 1) {
            throw new InvalidArgumentException('Spool max message bytes must be greater than zero when provided.');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            directory: ConfigValue::string($config, 'directory', ''),
            writeMetadata: ConfigValue::bool($config, 'writeMetadata', true),
            processingDirectory: ConfigValue::nullableString($config, 'processingDirectory'),
            extension: ConfigValue::string($config, 'extension', 'eml'),
            lockBeforeRead: ConfigValue::bool($config, 'lockBeforeRead', false),
            maxMessages: ConfigValue::int($config, 'maxMessages', 20),
            olderThanSeconds: ConfigValue::nullableInt($config, 'olderThanSeconds'),
            newerThanSeconds: ConfigValue::nullableInt($config, 'newerThanSeconds'),
            maxMessageBytes: ConfigValue::nullableInt($config, 'maxMessageBytes'),
        );
    }
}
