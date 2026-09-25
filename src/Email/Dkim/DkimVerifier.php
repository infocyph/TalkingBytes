<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Email\ValueObject\DkimVerificationReport;
use Infocyph\TalkingBytes\Email\ValueObject\DkimVerificationResult;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;

final readonly class DkimVerifier
{
    private DkimCanonicalizer $canonicalizer;

    private Clock $clock;

    private DkimSignatureValidator $signatureValidator;

    public function __construct(private DkimPublicKeyResolver $resolver, ?Clock $clock = null)
    {
        $this->clock = $clock ?? Clock::system();
        $this->canonicalizer = new DkimCanonicalizer();
        $this->signatureValidator = new DkimSignatureValidator($this->clock);
    }

    public function verify(ParsedEmail $email): DkimVerificationResult
    {
        [$headersBlock, $body] = $this->splitRaw($email->raw);
        $headers = $this->parseHeaderLines($headersBlock);

        $dkimField = $this->lastHeader($headers, 'dkim-signature');
        if ($dkimField === null) {
            return new DkimVerificationResult(false, reason: 'DKIM-Signature header not found.');
        }

        return $this->verifyField($headers, $body, $dkimField);
    }

    public function verifyAll(ParsedEmail $email): DkimVerificationReport
    {
        [$headersBlock, $body] = $this->splitRaw($email->raw);
        $headers = $this->parseHeaderLines($headersBlock);
        $results = [];

        foreach ($headers as $index => $field) {
            if (strcasecmp($field['name'], 'dkim-signature') !== 0) {
                continue;
            }

            $results[] = $this->verifyField(
                $headers,
                $body,
                [...$field, 'index' => $index],
            );
        }

        return new DkimVerificationReport($results);
    }

    /**
     * @param array<string, string> $tags
     */
    private function bodyFailure(
        array $tags,
        string $body,
        string $bodyCanon,
        string $domain,
        string $selector,
    ): ?DkimVerificationResult {
        $bodyHash = $this->nullableString($tags['bh'] ?? null);
        if ($bodyHash === null || strlen($bodyHash) > 128) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM body hash missing.');
        }

        $computedBodyHash = base64_encode(hash(
            'sha256',
            $this->canonicalizer->canonicalizeBody($body, $bodyCanon),
            true,
        ));
        if (hash_equals($bodyHash, $computedBodyHash)) {
            return null;
        }

        return new DkimVerificationResult(false, $domain, $selector, 'DKIM body hash mismatch.', [
            'expected_bh' => $bodyHash,
            'computed_bh' => $computedBodyHash,
        ]);
    }

    /**
     * @param list<array{name:string,value:string}> $headers
     */
    private function buildSigningInput(
        array $headers,
        string $hValue,
        string $dkimName,
        string $dkimValue,
        string $headerCanon,
        int $dkimIndex,
    ): ?string {
        $wantedHeaders = array_values(array_filter(array_map(
            static fn(string $name): string => strtolower(trim($name)),
            explode(':', strtolower($hValue)),
        ), static fn(string $value): bool => $value !== ''));

        if ($wantedHeaders === []) {
            return null;
        }

        $usedIndices = [$dkimIndex => true];
        $canonicalized = [];

        foreach ($wantedHeaders as $wantedHeader) {
            $index = $this->findHeaderIndexFromBottom($headers, $wantedHeader, $usedIndices);
            if ($index === null) {
                continue;
            }

            $usedIndices[$index] = true;
            $canonicalized[] = $this->canonicalizer->canonicalizeHeader(
                $headers[$index]['name'],
                $headers[$index]['value'],
                $headerCanon,
            );
        }

        $dkimWithoutSignature = preg_replace('/\bb=([^;]*)/i', 'b=', $dkimValue, 1);
        if (!is_string($dkimWithoutSignature)) {
            return null;
        }

        $canonicalized[] = $this->canonicalizer->canonicalizeHeader(
            $dkimName,
            $dkimWithoutSignature,
            $headerCanon,
        );

        return implode("\r\n", $canonicalized);
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

    private function identityMatchesDomainExactly(string $identity, string $domain): bool
    {
        $separator = strrpos($identity, '@');
        if ($separator === false) {
            return false;
        }

        return strtolower(substr($identity, $separator + 1)) === strtolower($domain);
    }

    /**
     * @param list<array{name:string,value:string}> $headers
     * @return array{name:string,value:string,index:int}|null
     */
    private function lastHeader(array $headers, string $name): ?array
    {
        for ($index = count($headers) - 1; $index >= 0; $index--) {
            if (strtolower((string) $headers[$index]['name']) !== strtolower($name)) {
                continue;
            }

            return [...$headers[$index], 'index' => $index];
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

        foreach ($this->rawHeaderGroups($headersBlock) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $parsed[] = ['name' => trim($name), 'value' => $value];
        }

        return $parsed;
    }

    /**
     * @param array<string, string> $tags
     */
    private function publicKey(
        array $tags,
        string $domain,
        string $selector,
        string $algorithm,
    ): string|DkimVerificationResult {
        $keyRecord = $this->resolver->resolve($domain, $selector);
        if ($keyRecord === null) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM public key record not found.');
        }

        $publicKey = DkimPublicKeyParser::parse($keyRecord, $algorithm);
        if ($publicKey === null) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM public key record is invalid.');
        }

        if (DkimPublicKeyParser::requiresStrictIdentity($keyRecord)
            && isset($tags['i'])
            && !$this->identityMatchesDomainExactly($tags['i'], $domain)
        ) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM key requires strict identity domain matching.');
        }

        return $publicKey;
    }

    /** @return list<string> */
    private function rawHeaderGroups(string $headers): array
    {
        $groups = [];
        foreach (preg_split('/\r\n/', $headers) ?: [] as $line) {
            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                $index = array_key_last($groups);
                if ($index !== null) {
                    $groups[$index] .= "\r\n" . $line;
                }

                continue;
            }
            $groups[] = $line;
        }

        return $groups;
    }

    /**
     * @param array<string, string> $tags
     * @return array{0:string,1:string}|DkimVerificationResult
     */
    private function signatureData(array $tags, string $domain, string $selector): array|DkimVerificationResult
    {
        $signature = $this->nullableString($tags['b'] ?? null);
        if ($signature === null || strlen($signature) > 8192) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM signature value missing.');
        }

        $headers = $this->nullableString($tags['h'] ?? null);
        if ($headers === null) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM signed header list missing.');
        }

        $names = array_map(static fn(string $name): string => strtolower(trim($name)), explode(':', $headers));
        if (!in_array('from', $names, true)) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM signed header list must include From.');
        }

        return [$signature, $headers];
    }

    /**
     * @param array<string, string> $tags
     * @return array{0:string,1:string,2:string}|DkimVerificationResult
     */
    private function signatureModes(array $tags, string $domain, string $selector): array|DkimVerificationResult
    {
        if (($tags['v'] ?? null) !== '1' || !isset($tags['a'])) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM version or algorithm tag is invalid.');
        }

        $algorithm = strtolower($tags['a']);
        if (!in_array($algorithm, ['rsa-sha256', 'ed25519-sha256'], true)) {
            return new DkimVerificationResult(false, $domain, $selector, 'Unsupported DKIM algorithm.');
        }

        $canon = strtolower((string) ($tags['c'] ?? 'simple/simple'));
        [$headerCanon, $bodyCanon] = array_pad(explode('/', $canon, 2), 2, 'simple');
        if (!in_array($headerCanon, ['simple', 'relaxed'], true)
            || !in_array($bodyCanon, ['simple', 'relaxed'], true)
        ) {
            return new DkimVerificationResult(false, $domain, $selector, 'Unsupported DKIM canonicalization.');
        }

        return [$algorithm, $headerCanon, $bodyCanon];
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

    /**
     * @param list<array{name:string,value:string}> $headers
     * @param array{name:string,value:string,index:int} $dkimField
     */
    private function verifyField(array $headers, string $body, array $dkimField): DkimVerificationResult
    {
        $dkimHeader = $dkimField['value'];
        if (strlen($dkimHeader) > 16_384) {
            return new DkimVerificationResult(false, reason: 'DKIM-Signature header exceeds safe bounds.');
        }

        $tags = DkimTagValueParser::parse($dkimHeader);
        $identity = $this->signatureValidator->validate($tags);
        if ($identity instanceof DkimVerificationResult) {
            return $identity;
        }
        [$domain, $selector] = $identity;

        $modes = $this->signatureModes($tags, $domain, $selector);
        if ($modes instanceof DkimVerificationResult) {
            return $modes;
        }
        [$algorithm, $headerCanon, $bodyCanon] = $modes;

        $bodyFailure = $this->bodyFailure($tags, $body, $bodyCanon, $domain, $selector);
        if ($bodyFailure !== null) {
            return $bodyFailure;
        }

        $signatureData = $this->signatureData($tags, $domain, $selector);
        if ($signatureData instanceof DkimVerificationResult) {
            return $signatureData;
        }
        [$signature, $h] = $signatureData;

        $publicKey = $this->publicKey($tags, $domain, $selector, $algorithm);
        if ($publicKey instanceof DkimVerificationResult) {
            return $publicKey;
        }

        $signingInput = $this->buildSigningInput(
            $headers,
            $h,
            $dkimField['name'],
            $dkimHeader,
            $headerCanon,
            $dkimField['index'],
        );
        if ($signingInput === null) {
            return new DkimVerificationResult(false, $domain, $selector, 'Signed header list could not be matched to message headers.');
        }

        if (!$this->verifySignature($algorithm, $signingInput, $signature, $publicKey)) {
            return new DkimVerificationResult(false, $domain, $selector, 'DKIM signature verification failed.');
        }

        return new DkimVerificationResult(true, $domain, $selector);
    }

    private function verifySignature(string $algorithm, string $input, string $signature, string $publicKey): bool
    {
        $decodedSignature = base64_decode($signature, true);
        if (!is_string($decodedSignature)) {
            return false;
        }
        if ($algorithm === 'ed25519-sha256') {
            return function_exists('sodium_crypto_sign_verify_detached')
                && strlen($decodedSignature) === SODIUM_CRYPTO_SIGN_BYTES
                && $publicKey !== ''
                && sodium_crypto_sign_verify_detached(
                    $decodedSignature,
                    hash('sha256', $input, true),
                    $publicKey,
                );
        }

        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            return false;
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['bits'] ?? 0) < 1024) {
            return false;
        }

        return openssl_verify($input, $decodedSignature, $key, OPENSSL_ALGO_SHA256) === 1;
    }
}
