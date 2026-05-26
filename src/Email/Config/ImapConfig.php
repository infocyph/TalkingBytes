<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use Infocyph\TalkingBytes\Email\Enum\ImapSecurity;

final readonly class ImapConfig
{
    public function __construct(
        public string $host,
        public string $username = '',
        public string $password = '',
        public string $defaultFolder = 'INBOX',
        public ImapSecurity $security = ImapSecurity::Ssl,
        public int $port = 993,
        public int $timeoutSeconds = 10,
    ) {
        MailboxConfigValidator::assertHost('IMAP', $this->host);
        MailboxConfigValidator::assertPort('IMAP', $this->port);
        MailboxConfigValidator::assertUsername('IMAP', $this->username);
        MailboxConfigValidator::assertPassword('IMAP', $this->password);
        MailboxConfigValidator::assertTimeout('IMAP', $this->timeoutSeconds);
        MailboxConfigValidator::assertRequired('IMAP default folder', $this->defaultFolder);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            host: ConfigValue::string($config, 'host', ''),
            port: ConfigValue::int($config, 'port', 993),
            security: self::securityFrom($config),
            username: ConfigValue::string($config, 'username', ''),
            password: ConfigValue::string($config, 'password', ''),
            timeoutSeconds: ConfigValue::int($config, 'timeoutSeconds', 10),
            defaultFolder: ConfigValue::string($config, 'defaultFolder', 'INBOX'),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function securityFrom(array $config): ImapSecurity
    {
        $raw = ConfigValue::string($config, 'security', ImapSecurity::Ssl->value);

        return ImapSecurity::tryFrom($raw) ?? ImapSecurity::Ssl;
    }
}
