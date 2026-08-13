<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use Infocyph\TalkingBytes\Email\Enum\DkimAlgorithm;
use InvalidArgumentException;
use RuntimeException;

final readonly class DkimConfig
{
    /**
     * @param list<string> $headersToSign
     */
    public function __construct(
        public string $domain,
        public string $selector,
        #[\SensitiveParameter]
        public string $privateKey,
        public array $headersToSign = ['from', 'to', 'subject', 'date', 'message-id', 'mime-version', 'content-type'],
        public DkimAlgorithm $algorithm = DkimAlgorithm::RsaSha256,
        public string $headerCanonicalization = 'relaxed',
        public string $bodyCanonicalization = 'relaxed',
    ) {
        if (trim($this->domain) === '') {
            throw new InvalidArgumentException('DKIM domain is required.');
        }

        if (trim($this->selector) === '') {
            throw new InvalidArgumentException('DKIM selector is required.');
        }

        if (trim($this->privateKey) === '') {
            throw new InvalidArgumentException('DKIM private key is required.');
        }

        if ($this->headersToSign === []) {
            throw new InvalidArgumentException('DKIM requires at least one signed header.');
        }
        $normalizedHeaders = array_map(strtolower(...), $this->headersToSign);
        if (!in_array('from', $normalizedHeaders, true)) {
            throw new InvalidArgumentException('DKIM signed headers must include From.');
        }
        foreach ([$this->headerCanonicalization, $this->bodyCanonicalization] as $canonicalization) {
            if (!in_array($canonicalization, ['simple', 'relaxed'], true)) {
                throw new InvalidArgumentException('DKIM canonicalization must be simple or relaxed.');
            }
        }
        self::assertDnsIdentifier($this->domain, 255, 'domain');
        self::assertDnsIdentifier($this->selector, 63, 'selector');

        $this->assertPrivateKeyIsReadable($this->privateKey);
    }

    /**
     * @param list<string> $headersToSign
     */
    public static function fromPrivateKeyPath(
        string $domain,
        string $selector,
        string $privateKeyPath,
        array $headersToSign = ['from', 'to', 'subject', 'date', 'message-id', 'mime-version', 'content-type'],
        DkimAlgorithm $algorithm = DkimAlgorithm::RsaSha256,
    ): self {
        if (!is_file($privateKeyPath) || !is_readable($privateKeyPath)) {
            throw new InvalidArgumentException(sprintf('DKIM private key path is not readable: %s', $privateKeyPath));
        }

        $privateKey = file_get_contents($privateKeyPath);
        if (!is_string($privateKey) || trim($privateKey) === '') {
            throw new InvalidArgumentException(sprintf('DKIM private key file is empty: %s', $privateKeyPath));
        }

        return new self($domain, $selector, $privateKey, $headersToSign, $algorithm);
    }

    /**
     * @param list<string> $headersToSign
     */
    public static function fromPrivateKeyString(
        string $domain,
        string $selector,
        #[\SensitiveParameter]
        string $privateKey,
        array $headersToSign = ['from', 'to', 'subject', 'date', 'message-id', 'mime-version', 'content-type'],
        DkimAlgorithm $algorithm = DkimAlgorithm::RsaSha256,
    ): self {
        return new self($domain, $selector, $privateKey, $headersToSign, $algorithm);
    }

    private static function assertDnsIdentifier(string $value, int $maximumBytes, string $label): void
    {
        if (strlen($value) > $maximumBytes) {
            throw new InvalidArgumentException(sprintf('DKIM %s exceeds %d bytes.', $label, $maximumBytes));
        }

        foreach (explode('.', $value) as $part) {
            if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $part) !== 1) {
                throw new InvalidArgumentException(sprintf('DKIM %s contains an invalid DNS label.', $label));
            }
        }
    }

    private function assertPrivateKeyIsReadable(string $privateKey): void
    {
        if ($this->algorithm === DkimAlgorithm::Ed25519Sha256) {
            if (!function_exists('sodium_crypto_sign_detached')) {
                throw new RuntimeException('Sodium extension is required for Ed25519 DKIM signing.');
            }
            $decoded = base64_decode(trim($privateKey), true);
            if (!is_string($decoded) || !in_array(strlen($decoded), [32, 64], true)) {
                throw new InvalidArgumentException('Ed25519 DKIM private key must be a base64-encoded 32-byte seed or 64-byte secret key.');
            }

            return;
        }

        if (!function_exists('openssl_pkey_get_private')) {
            throw new RuntimeException('OpenSSL extension is required for DKIM signing.');
        }

        $resource = openssl_pkey_get_private($privateKey);
        if ($resource === false) {
            throw new InvalidArgumentException('Invalid DKIM private key.');
        }
    }
}
