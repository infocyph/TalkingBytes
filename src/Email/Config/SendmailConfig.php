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
        public ?int $maxMessageBytes = null,
    ) {
        if (trim($this->path) === '') {
            throw new InvalidArgumentException('Sendmail path is required.');
        }

        if ($this->timeoutSeconds < 1) {
            throw new InvalidArgumentException('Sendmail timeout must be greater than zero.');
        }

        if ($this->maxMessageBytes !== null && $this->maxMessageBytes < 1) {
            throw new InvalidArgumentException('Sendmail max message bytes must be greater than zero when provided.');
        }

        foreach ($this->extraArguments as $argument) {
            if (trim($argument) === '') {
                throw new InvalidArgumentException('Sendmail arguments must not contain empty values.');
            }

            if (preg_match('/[\x00\r\n]/', $argument) === 1) {
                throw new InvalidArgumentException('Sendmail arguments must not contain control characters.');
            }

            if (preg_match('/\s/', $argument) === 1) {
                throw new InvalidArgumentException('Sendmail arguments must not contain whitespace. Split composite values into separate arguments.');
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            path: ConfigValue::string($config, 'path', '/usr/sbin/sendmail'),
            extraArguments: ConfigValue::stringList($config, 'extraArguments', ['-t', '-i']),
            timeoutSeconds: ConfigValue::int($config, 'timeoutSeconds', 15),
            maxMessageBytes: ConfigValue::nullableInt($config, 'maxMessageBytes'),
        );
    }
}
