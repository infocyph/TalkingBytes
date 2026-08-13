<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Email\ValueObject\DkimVerificationResult;

final readonly class DkimSignatureValidator
{
    public function __construct(private Clock $clock) {}

    /**
     * @param array<string, string> $tags
     * @return array{0:string,1:string}|DkimVerificationResult
     */
    public function validate(array $tags): array|DkimVerificationResult
    {
        $domain = self::nullableString($tags['d'] ?? null);
        $selector = self::nullableString($tags['s'] ?? null);
        if ($domain === null || $selector === null) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM domain/selector missing.');
        }

        $reason = $this->validationFailure($tags, $domain, $selector);
        if ($reason !== null) {
            return new DkimVerificationResult(false, $domain, $selector, $reason);
        }

        return [$domain, $selector];
    }

    private static function identityFailure(string $identity, string $domain): ?string
    {
        $identityDomain = strtolower(substr(strrchr($identity, '@') ?: '', 1));
        $domain = strtolower($domain);
        if ($identityDomain === ''
            || ($identityDomain !== $domain && !str_ends_with($identityDomain, '.' . $domain))
        ) {
            return 'DKIM identity is outside the signing domain.';
        }

        return null;
    }

    private static function isDnsIdentifier(string $value): bool
    {
        return array_all(
            explode('.', $value),
            static fn(string $part): bool => preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $part) === 1,
        );
    }

    /** @return list<string> */
    private static function lowercaseList(string $value): array
    {
        return array_map(
            static fn(string $item): string => strtolower(trim($item)),
            explode(':', $value),
        );
    }

    private static function nullableString(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, string> $tags */
    private function timestampFailure(array $tags): ?string
    {
        $issuedAt = $tags['t'] ?? null;
        if ($issuedAt !== null && !ctype_digit($issuedAt)) {
            return 'DKIM signature timestamp is invalid.';
        }

        $expires = $tags['x'] ?? null;
        if ($expires === null) {
            return null;
        }
        if (!ctype_digit($expires)
            || (int) $expires < (int) $this->clock->timestamp()
            || ($issuedAt !== null && (int) $expires <= (int) $issuedAt)
        ) {
            return 'DKIM signature has expired or has invalid expiry.';
        }

        return null;
    }

    /** @param array<string, string> $tags */
    private function validationFailure(array $tags, string $domain, string $selector): ?string
    {
        if (strlen($domain) > 255 || strlen($selector) > 63 || strlen($tags['h'] ?? '') > 4096) {
            return 'DKIM identifiers exceed safe bounds.';
        }
        if (!self::isDnsIdentifier($domain) || !self::isDnsIdentifier($selector)) {
            return 'DKIM domain or selector is invalid.';
        }
        if (array_key_exists('l', $tags)) {
            return 'DKIM body-length tag is not supported.';
        }

        $timestampFailure = $this->timestampFailure($tags);
        if ($timestampFailure !== null) {
            return $timestampFailure;
        }
        if (isset($tags['q']) && !in_array('dns/txt', self::lowercaseList($tags['q']), true)) {
            return 'DKIM query method is unsupported.';
        }

        return isset($tags['i']) ? self::identityFailure($tags['i'], $domain) : null;
    }
}
