<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use Infocyph\TalkingBytes\Email\Enum\ImapSecurity;
use InvalidArgumentException;

final readonly class ImapConfig
{
    public function __construct(
        public string $host,
        #[\SensitiveParameter]
        public string $username = '',
        #[\SensitiveParameter]
        public string $password = '',
        public string $defaultFolder = 'INBOX',
        public ImapSecurity $security = ImapSecurity::Ssl,
        public int $port = 993,
        public int $timeoutSeconds = 10,
        public int $maxLiteralBytes = 10_485_760,
        public int $maxResponseBytes = 16_777_216,
        public int $maxResponseLines = 10_000,
    ) {
        MailboxConfigValidator::assertHost('IMAP', $this->host);
        MailboxConfigValidator::assertPort('IMAP', $this->port);
        MailboxConfigValidator::assertUsername('IMAP', $this->username);
        MailboxConfigValidator::assertPassword('IMAP', $this->password);
        MailboxConfigValidator::assertTimeout('IMAP', $this->timeoutSeconds);
        MailboxConfigValidator::assertRequired('IMAP default folder', $this->defaultFolder);
        foreach ([$this->maxLiteralBytes, $this->maxResponseBytes, $this->maxResponseLines] as $limit) {
            if ($limit < 1) {
                throw new InvalidArgumentException('IMAP response limits must be greater than zero.');
            }
        }
        if ($this->security === ImapSecurity::None && !$this->isLoopbackHost()) {
            throw new InvalidArgumentException('IMAP credentials cannot be sent over a plaintext connection.');
        }
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
            maxLiteralBytes: ConfigValue::int($config, 'maxLiteralBytes', 10_485_760),
            maxResponseBytes: ConfigValue::int($config, 'maxResponseBytes', 16_777_216),
            maxResponseLines: ConfigValue::int($config, 'maxResponseLines', 10_000),
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

    private function isLoopbackHost(): bool
    {
        return in_array(strtolower(trim($this->host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }
}
