<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

final readonly class StaticDkimPublicKeyResolver implements DkimPublicKeyResolver
{
    /**
     * @param array<string, string> $records
     */
    public function __construct(private array $records) {}

    public function resolve(string $domain, string $selector): ?string
    {
        $recordName = strtolower(sprintf('%s._domainkey.%s', trim($selector), trim($domain)));

        return $this->records[$recordName] ?? null;
    }
}
