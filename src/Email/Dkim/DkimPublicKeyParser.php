<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

final readonly class DkimPublicKeyParser
{
    public static function parse(string $record, string $algorithm): ?string
    {
        $tags = DkimTagValueParser::parse($record);
        $expectedType = $algorithm === 'ed25519-sha256' ? 'ed25519' : 'rsa';
        if (!self::allowsAlgorithm($tags, $expectedType) || !self::allowsEmailService($tags)) {
            return null;
        }

        $base64 = preg_replace('/\s+/', '', $tags['p'] ?? '') ?? '';
        if ($base64 === '') {
            return null;
        }

        return $expectedType === 'ed25519'
            ? self::ed25519Key($base64)
            : self::rsaKey($base64);
    }

    /** @param array<string, string> $tags */
    private static function allowsAlgorithm(array $tags, string $expectedType): bool
    {
        if (isset($tags['v']) && strtoupper($tags['v']) !== 'DKIM1') {
            return false;
        }
        if (strtolower($tags['k'] ?? 'rsa') !== $expectedType) {
            return false;
        }

        $hashes = self::lowercaseList($tags['h'] ?? 'sha256');

        return in_array('sha256', $hashes, true);
    }

    /** @param array<string, string> $tags */
    private static function allowsEmailService(array $tags): bool
    {
        $services = self::lowercaseList($tags['s'] ?? '*');

        return in_array('*', $services, true) || in_array('email', $services, true);
    }

    private static function ed25519Key(string $base64): ?string
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }

        $decoded = base64_decode($base64, true);

        return is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            ? $decoded
            : null;
    }

    /** @return list<string> */
    private static function lowercaseList(string $value): array
    {
        return array_map(
            static fn(string $item): string => strtolower(trim($item)),
            explode(':', $value),
        );
    }

    private static function rsaKey(string $base64): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($base64, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}
