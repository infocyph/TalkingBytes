<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

use Infocyph\TalkingBytes\Core\Support\Clock;
use InvalidArgumentException;

final class CachedDkimPublicKeyResolver implements DkimPublicKeyResolver
{
    private readonly Clock $clock;

    /**
     * @var array<string, array{value:string|null,expiresAt:float}>
     */
    private array $cache = [];

    public function __construct(
        private readonly DkimPublicKeyResolver $inner,
        private readonly int $positiveTtlSeconds = 3600,
        private readonly int $negativeTtlSeconds = 60,
        private readonly int $capacity = 1024,
        ?Clock $clock = null,
    ) {
        if ($this->positiveTtlSeconds < 1 || $this->negativeTtlSeconds < 1 || $this->capacity < 1) {
            throw new InvalidArgumentException('DKIM cache TTLs and capacity must be greater than zero.');
        }
        $this->clock = $clock ?? Clock::system();
    }

    public function resolve(string $domain, string $selector): ?string
    {
        $key = strtolower(sprintf('%s._domainkey.%s', trim($selector), trim($domain)));

        $now = $this->clock->timestamp();
        $cached = $this->cache[$key] ?? null;
        if ($cached !== null && $cached['expiresAt'] > $now) {
            return $cached['value'];
        }
        unset($this->cache[$key]);

        if (count($this->cache) >= $this->capacity) {
            array_shift($this->cache);
        }

        $value = $this->inner->resolve($domain, $selector);
        $ttl = $value === null ? $this->negativeTtlSeconds : $this->positiveTtlSeconds;
        $this->cache[$key] = ['value' => $value, 'expiresAt' => $now + $ttl];

        return $value;
    }
}
