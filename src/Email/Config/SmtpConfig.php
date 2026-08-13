<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use Infocyph\TalkingBytes\Email\Enum\SmtpAuthMechanism;
use Infocyph\TalkingBytes\Email\Enum\SmtpSecurity;
use Infocyph\TalkingBytes\Email\Enum\SmtpUtf8Policy;
use InvalidArgumentException;

final readonly class SmtpConfig
{
    public function __construct(
        public string $host,
        public int $port = 587,
        public SmtpSecurity $security = SmtpSecurity::StartTlsRequired,
        public ?SmtpCredentials $credentials = null,
        public int $timeoutSeconds = 10,
        public string $localDomain = 'localhost',
        public SmtpAuthMechanism $authMechanism = SmtpAuthMechanism::Auto,
        public bool $captureTranscript = false,
        public SmtpUtf8Policy $utf8Policy = SmtpUtf8Policy::Auto,
        public bool $allowEightBitMime = true,
        public ?int $maxMessageBytes = null,
        public ?string $caBundle = null,
        public ?string $clientCertificate = null,
        public ?string $clientKey = null,
        public ?string $clientKeyPassphrase = null,
    ) {
        $this->validateConnection();
        $this->validateSecurity();
        $this->validateTlsFiles();
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $security = SmtpSecurity::tryFrom(ConfigValue::string($config, 'security', SmtpSecurity::StartTlsRequired->value))
            ?? SmtpSecurity::StartTlsRequired;

        $authMechanism = SmtpAuthMechanism::tryFrom(ConfigValue::string($config, 'authMechanism', SmtpAuthMechanism::Auto->value))
            ?? SmtpAuthMechanism::Auto;

        $utf8Policy = SmtpUtf8Policy::tryFrom(ConfigValue::string($config, 'utf8Policy', SmtpUtf8Policy::Auto->value))
            ?? SmtpUtf8Policy::Auto;

        $credentials = null;
        $credentialsValue = $config['credentials'] ?? null;
        if (is_array($credentialsValue)) {
            /** @var array<string, mixed> $credentialsConfig */
            $credentialsConfig = $credentialsValue;
            $credentials = SmtpCredentials::fromArray($credentialsConfig);
        }

        return new self(
            host: ConfigValue::string($config, 'host', ''),
            port: ConfigValue::int($config, 'port', 587),
            security: $security,
            credentials: $credentials,
            timeoutSeconds: ConfigValue::int($config, 'timeoutSeconds', 10),
            localDomain: ConfigValue::string($config, 'localDomain', 'localhost'),
            authMechanism: $authMechanism,
            captureTranscript: ConfigValue::bool($config, 'captureTranscript', false),
            utf8Policy: $utf8Policy,
            allowEightBitMime: ConfigValue::bool($config, 'allowEightBitMime', true),
            maxMessageBytes: ConfigValue::nullableInt($config, 'maxMessageBytes'),
            caBundle: ConfigValue::nullableString($config, 'caBundle'),
            clientCertificate: ConfigValue::nullableString($config, 'clientCertificate'),
            clientKey: ConfigValue::nullableString($config, 'clientKey'),
            clientKeyPassphrase: ConfigValue::nullableString($config, 'clientKeyPassphrase'),
        );
    }

    private function validateConnection(): void
    {
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
        if (strlen($this->localDomain) > 255
            || preg_match('/[\x00-\x20\x7F]/', $this->localDomain) === 1
            || preg_match('/^(?:\[[A-Fa-f0-9:.]+\]|[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?)$/D', $this->localDomain) !== 1
        ) {
            throw new InvalidArgumentException('SMTP local domain is not a valid EHLO/HELO identity.');
        }
    }

    private function validateSecurity(): void
    {
        if ($this->credentials !== null && $this->security === SmtpSecurity::None) {
            throw new InvalidArgumentException('SMTP credentials cannot be sent over a plaintext connection.');
        }
        if ($this->maxMessageBytes !== null && $this->maxMessageBytes < 1) {
            throw new InvalidArgumentException('SMTP max message bytes must be greater than zero when provided.');
        }
    }

    private function validateTlsFiles(): void
    {
        foreach (['CA bundle' => $this->caBundle, 'client certificate' => $this->clientCertificate, 'client key' => $this->clientKey] as $label => $path) {
            if ($path !== null && (!is_file($path) || !is_readable($path))) {
                throw new InvalidArgumentException(sprintf('SMTP %s is not readable: %s', $label, $path));
            }
        }
    }
}
