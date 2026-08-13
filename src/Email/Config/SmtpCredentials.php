<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use InvalidArgumentException;

final readonly class SmtpCredentials
{
    public function __construct(
        #[\SensitiveParameter]
        public string $username,
        #[\SensitiveParameter]
        public string $password,
    ) {
        if (preg_match('/[\x00-\x1F\x7F]/', $this->username . $this->password) === 1) {
            throw new InvalidArgumentException('SMTP credentials must not contain ASCII control characters.');
        }
        if (trim($this->username) === '') {
            throw new InvalidArgumentException('SMTP username is required when credentials are provided.');
        }

        if ($this->password === '') {
            throw new InvalidArgumentException('SMTP password is required when credentials are provided.');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            username: ConfigValue::string($config, 'username', ''),
            password: ConfigValue::string($config, 'password', ''),
        );
    }
}
