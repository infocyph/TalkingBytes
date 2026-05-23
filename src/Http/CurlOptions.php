<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

final readonly class CurlOptions
{
    /**
     * @param array<int, mixed> $additional
     */
    public function __construct(
        public int $timeoutSeconds = 10,
        public int $connectTimeoutSeconds = 10,
        public bool $followRedirects = true,
        public int $maxRedirects = 10,
        public ?string $proxy = null,
        public bool $verifyPeer = true,
        public bool $verifyHost = true,
        public ?string $clientCertificate = null,
        public ?string $clientKey = null,
        public ?string $clientKeyPassphrase = null,
        public ?string $downloadPath = null,
        public array $additional = [],
    ) {}

    public function withAdditional(int $option, mixed $value): self
    {
        $additional = $this->additional;
        $additional[$option] = $value;

        return new self(
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
            $this->followRedirects,
            $this->maxRedirects,
            $this->proxy,
            $this->verifyPeer,
            $this->verifyHost,
            $this->clientCertificate,
            $this->clientKey,
            $this->clientKeyPassphrase,
            $this->downloadPath,
            $additional,
        );
    }
}
