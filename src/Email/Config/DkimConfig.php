<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use InvalidArgumentException;

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
    }
}
