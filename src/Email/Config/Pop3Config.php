<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use Infocyph\TalkingBytes\Email\Enum\Pop3Security;
use InvalidArgumentException;

final readonly class Pop3Config
{
    public function __construct(
        public string $host,
        public int $port = 110,
        public Pop3Security $security = Pop3Security::StartTlsRequired,
        #[\SensitiveParameter]
        public string $username = '',
        #[\SensitiveParameter]
        public string $password = '',
        public int $timeoutSeconds = 10,
        public int $maxResponseBytes = 10_485_760,
        public int $maxResponseLines = 100_000,
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
            'maxResponseBytes' => ConfigValue::int($config, 'maxResponseBytes', 10_485_760),
            'maxResponseLines' => ConfigValue::int($config, 'maxResponseLines', 100_000),
        ];

        return new self(...$values);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function parseSecurity(array $config): Pop3Security
    {
        $raw = ConfigValue::string($config, 'security', Pop3Security::StartTlsRequired->value);

        return Pop3Security::tryFrom($raw) ?? Pop3Security::StartTlsRequired;
    }

    private function isLoopbackHost(): bool
    {
        return in_array(strtolower(trim($this->host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    private function validate(): void
    {
        foreach ($this->validators() as $validator) {
            $validator();
        }
        if ($this->security === Pop3Security::None && !$this->isLoopbackHost()) {
            throw new InvalidArgumentException('POP3 credentials cannot be sent over a plaintext connection.');
        }
        if ($this->maxResponseBytes < 1 || $this->maxResponseLines < 1) {
            throw new InvalidArgumentException('POP3 response limits must be greater than zero.');
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
