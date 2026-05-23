<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use InvalidArgumentException;

final readonly class SpoolConfig
{
    public function __construct(
        public string $directory,
        public bool $writeMetadata = true,
    ) {
        if (trim($this->directory) === '') {
            throw new InvalidArgumentException('Spool directory is required.');
        }
    }
}
