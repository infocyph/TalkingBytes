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
        public string $privateKey,
        public array $headersToSign = ['from', 'to', 'subject', 'date', 'message-id', 'mime-version', 'content-type'],
        public DkimAlgorithm $algorithm = DkimAlgorithm::RsaSha256,
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
        string $privateKey,
        array $headersToSign = ['from', 'to', 'subject', 'date', 'message-id', 'mime-version', 'content-type'],
        DkimAlgorithm $algorithm = DkimAlgorithm::RsaSha256,
    ): self {
        return new self($domain, $selector, $privateKey, $headersToSign, $algorithm);
    }

    private function assertPrivateKeyIsReadable(string $privateKey): void
    {
        if (!function_exists('openssl_pkey_get_private')) {
            throw new RuntimeException('OpenSSL extension is required for DKIM signing.');
        }

        $resource = openssl_pkey_get_private($privateKey);
        if ($resource === false) {
            throw new InvalidArgumentException('Invalid DKIM private key.');
        }
    }
}
