<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use InvalidArgumentException;

final readonly class SendmailConfig
{
    /**
     * @param list<string> $extraArguments
     */
    public function __construct(
        public string $path = '/usr/sbin/sendmail',
        public array $extraArguments = ['-t', '-i'],
        public int $timeoutSeconds = 15,
    ) {
        if (trim($this->path) === '') {
            throw new InvalidArgumentException('Sendmail path is required.');
        }

        if ($this->timeoutSeconds < 1) {
            throw new InvalidArgumentException('Sendmail timeout must be greater than zero.');
        }

        foreach ($this->extraArguments as $argument) {
            if (trim($argument) === '') {
                throw new InvalidArgumentException('Sendmail arguments must not contain empty values.');
            }
        }
    }
}
