<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

use Infocyph\TalkingBytes\Email\ValueObject\DkimVerificationResult;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;

final readonly class DkimVerifier
{
    public function __construct(private DkimPublicKeyResolver $resolver) {}

    public function verify(ParsedEmail $email): DkimVerificationResult
    {
        [$headersBlock, $body] = $this->splitRaw($email->raw);
        $headers = $this->parseHeaderLines($headersBlock);

        $dkimHeader = $this->lastHeaderValue($headers, 'dkim-signature');
        if ($dkimHeader === null) {
            return new DkimVerificationResult(false, reason: 'DKIM-Signature header not found.');
        }

        $tags = $this->parseTagValueList($dkimHeader);
        $domain = $this->nullableString($tags['d'] ?? null);
        $selector = $this->nullableString($tags['s'] ?? null);

        if ($domain === null || $selector === null) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM domain/selector missing.');
        }

        $algorithm = strtolower((string) ($tags['a'] ?? 'rsa-sha256'));
        if ($algorithm !== 'rsa-sha256') {
            return new DkimVerificationResult(false, $domain, $selector, 'Unsupported DKIM algorithm.');
        }

        $canon = strtolower((string) ($tags['c'] ?? 'simple/simple'));
        [$headerCanon, $bodyCanon] = array_pad(explode('/', $canon, 2), 2, 'simple');

        $bodyHash = $this->nullableString($tags['bh'] ?? null);
        if ($bodyHash === null) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM body hash missing.');
        }

        $computedBodyHash = base64_encode(hash('sha256', $this->canonicalizeBody($body, $bodyCanon), true));
        if (!hash_equals($bodyHash, $computedBodyHash)) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM body hash mismatch.', [
                'expected_bh' => $bodyHash,
                'computed_bh' => $computedBodyHash,
            ]);
        }

        $signature = $this->nullableString($tags['b'] ?? null);
        if ($signature === null) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM signature value missing.');
        }

        $h = $this->nullableString($tags['h'] ?? null);
        if ($h === null) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM signed header list missing.');
        }

        $keyRecord = $this->resolver->resolve($domain, $selector);
        if ($keyRecord === null) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM public key record not found.');
        }

        $publicKeyPem = $this->publicKeyPemFromRecord($keyRecord);
        if ($publicKeyPem === null) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM public key record is invalid.');
        }

        $signingInput = $this->buildSigningInput($headers, $h, $dkimHeader, $headerCanon);
        if ($signingInput === null) {
            return new DkimVerificationResult(false, $domain, $selector, 'Signed header list could not be matched to message headers.');
        }

        $verified = openssl_verify(
            $signingInput,
            base64_decode($signature, true) ?: '',
            $publicKeyPem,
            OPENSSL_ALGO_SHA256,
        ) === 1;

        if (!$verified) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM signature verification failed.');
        }

        return new DkimVerificationResult(true, $domain, $selector);
    }

    /**
     * @param list<array{name:string,value:string}> $headers
     */
    private function buildSigningInput(array $headers, string $hValue, string $dkimValue, string $headerCanon): ?string
    {
        $wantedHeaders = array_values(array_filter(array_map(
            static fn(string $name): string => strtolower(trim($name)),
            explode(':', strtolower($hValue)),
        ), static fn(string $value): bool => $value !== ''));

        if ($wantedHeaders === []) {
            return null;
        }

        $usedIndices = [];
        $canonicalized = [];

        foreach ($wantedHeaders as $wantedHeader) {
            $index = $this->findHeaderIndexFromBottom($headers, $wantedHeader, $usedIndices);
            if ($index === null) {
                return null;
            }

            $usedIndices[$index] = true;
            $canonicalized[] = $this->canonicalizeHeader(
                $headers[$index]['name'],
                $headers[$index]['value'],
                $headerCanon,
            );
        }

        $dkimWithoutSignature = preg_replace('/\bb=([^;]*)/i', 'b=', $dkimValue, 1);
        if (!is_string($dkimWithoutSignature)) {
            return null;
        }

        $canonicalized[] = $this->canonicalizeHeader('DKIM-Signature', $dkimWithoutSignature, $headerCanon);

        return implode("\r\n", $canonicalized);
    }

    private function canonicalizeBody(string $body, string $algorithm): string
    {
        $algorithm = strtolower(trim($algorithm));

        $normalized = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $body));
        $lines = explode("\r\n", $normalized);

        if ($algorithm === 'relaxed') {
            foreach ($lines as &$line) {
                $line = rtrim(preg_replace('/[ \t]+/', ' ', $line) ?? $line, ' ');
            }
            unset($line);
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private function canonicalizeHeader(string $name, string $value, string $algorithm): string
    {
        $algorithm = strtolower(trim($algorithm));

        if ($algorithm === 'relaxed') {
            $normalizedName = strtolower(trim($name));
            $normalizedValue = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);

            return sprintf('%s:%s', $normalizedName, $normalizedValue);
        }

        return sprintf('%s:%s', trim($name), ltrim($value));
    }

    /**
     * @param list<array{name:string,value:string}> $headers
     * @param array<int, true> $usedIndices
     */
    private function findHeaderIndexFromBottom(array $headers, string $name, array $usedIndices): ?int
    {
        for ($index = count($headers) - 1; $index >= 0; $index--) {
            if (array_key_exists($index, $usedIndices)) {
                continue;
            }

            if (strtolower((string) $headers[$index]['name']) !== $name) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * @param list<array{name:string,value:string}> $headers
     */
    private function lastHeaderValue(array $headers, string $name): ?string
    {
        for ($index = count($headers) - 1; $index >= 0; $index--) {
            if (strtolower((string) $headers[$index]['name']) !== strtolower($name)) {
                continue;
            }

            return (string) $headers[$index]['value'];
        }

        return null;
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<array{name:string,value:string}>
     */
    private function parseHeaderLines(string $headersBlock): array
    {
        $parsed = [];

        foreach (DkimHeaderTools::unfoldLines($headersBlock) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $parsed[] = ['name' => trim($name), 'value' => ltrim($value)];
        }

        return $parsed;
    }

    /**
     * @return array<string, string>
     */
    private function parseTagValueList(string $headerValue): array
    {
        $parts = preg_split('/\s*;\s*/', trim($headerValue)) ?: [];
        $tags = [];

        foreach ($parts as $part) {
            if ($part === '' || !str_contains($part, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $part, 2);
            $tags[strtolower(trim($name))] = trim($value);
        }

        return $tags;
    }

    private function publicKeyPemFromRecord(string $record): ?string
    {
        $tags = $this->parseTagValueList($record);
        $publicKey = $this->nullableString($tags['p'] ?? null);

        if ($publicKey === null) {
            return null;
        }

        $base64 = preg_replace('/\s+/', '', $publicKey) ?? $publicKey;
        if ($base64 === '') {
            return null;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split($base64, 64, "\n");

        return $pem . "-----END PUBLIC KEY-----\n";
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitRaw(string $raw): array
    {
        $normalized = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $raw));
        $parts = preg_split("/\r\n\r\n/", $normalized, 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
