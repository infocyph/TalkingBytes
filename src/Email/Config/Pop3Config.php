<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use Infocyph\TalkingBytes\Email\Enum\Pop3Security;

final readonly class Pop3Config
{
    public function __construct(
        public string $host,
        public int $port = 110,
        public Pop3Security $security = Pop3Security::None,
        public string $username = '',
        public string $password = '',
        public int $timeoutSeconds = 10,
    ) {
        $this->validate();
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $values = [
            'host' => ConfigValue::string($config, 'host', ''),
            'port' => ConfigValue::int($config, 'port', 110),
            'security' => self::parseSecurity($config),
            'username' => ConfigValue::string($config, 'username', ''),
            'password' => ConfigValue::string($config, 'password', ''),
            'timeoutSeconds' => ConfigValue::int($config, 'timeoutSeconds', 10),
        ];

        return new self(...$values);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function parseSecurity(array $config): Pop3Security
    {
        $raw = ConfigValue::string($config, 'security', Pop3Security::None->value);

        return Pop3Security::tryFrom($raw) ?? Pop3Security::None;
    }

    private function validate(): void
    {
        foreach ($this->validators() as $validator) {
            $validator();
        }
    }

    /**
     * @return list<callable():void>
     */
    private function validators(): array
    {
        return [
            function (): void {
                MailboxConfigValidator::assertHost('POP3', $this->host);
            },
            function (): void {
                MailboxConfigValidator::assertUsername('POP3', $this->username);
            },
            function (): void {
                MailboxConfigValidator::assertPassword('POP3', $this->password);
            },
            function (): void {
                MailboxConfigValidator::assertPort('POP3', $this->port);
            },
            function (): void {
                MailboxConfigValidator::assertTimeout('POP3', $this->timeoutSeconds);
            },
        ];
    }
}
