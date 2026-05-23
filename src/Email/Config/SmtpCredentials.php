<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use InvalidArgumentException;

final readonly class SmtpCredentials
{
    public function __construct(
        public string $username,
        public string $password,
    ) {
        if (trim($this->username) === '') {
            throw new InvalidArgumentException('SMTP username is required when credentials are provided.');
        }

        if ($this->password === '') {
            throw new InvalidArgumentException('SMTP password is required when credentials are provided.');
        }
    }
}
