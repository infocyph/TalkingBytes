<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

final class CachedDkimPublicKeyResolver implements DkimPublicKeyResolver
{
    /**
     * @var array<string, string|null>
     */
    private array $cache = [];

    public function __construct(private readonly DkimPublicKeyResolver $inner) {}

    public function resolve(string $domain, string $selector): ?string
    {
        $key = strtolower(sprintf('%s._domainkey.%s', trim($selector), trim($domain)));

        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->inner->resolve($domain, $selector);
        }

        return $this->cache[$key];
    }
}
