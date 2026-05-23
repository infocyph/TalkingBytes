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
    ) {
        if (trim($this->directory) === '') {
            throw new InvalidArgumentException('Log directory is required.');
        }

        if (trim($this->filenamePrefix) === '') {
            throw new InvalidArgumentException('Log filename prefix is required.');
        }
    }
}
