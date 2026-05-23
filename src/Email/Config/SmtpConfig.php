<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use Infocyph\TalkingBytes\Email\Enum\SmtpAuthMechanism;
use Infocyph\TalkingBytes\Email\Enum\SmtpSecurity;
use InvalidArgumentException;

final readonly class SmtpConfig
{
    public function __construct(
        public string $host,
        public int $port = 587,
        public SmtpSecurity $security = SmtpSecurity::StartTls,
        public ?SmtpCredentials $credentials = null,
        public int $timeoutSeconds = 10,
        public string $localDomain = 'localhost',
        public SmtpAuthMechanism $authMechanism = SmtpAuthMechanism::Auto,
    ) {
        if (trim($this->host) === '') {
            throw new InvalidArgumentException('SMTP host is required.');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('SMTP port must be between 1 and 65535.');
        }

        if ($this->timeoutSeconds < 1) {
            throw new InvalidArgumentException('SMTP timeout must be greater than zero.');
        }

        if (trim($this->localDomain) === '') {
            throw new InvalidArgumentException('SMTP local domain is required.');
        }
    }
}
