<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use RuntimeException;

final readonly class DkimSigner
{
    public function __construct(private DkimCanonicalizer $canonicalizer = new DkimCanonicalizer()) {}

    public function buildSignatureHeader(string $headers, string $body, DkimConfig $config): string
    {
        $bodyHash = base64_encode(hash('sha256', $this->canonicalizer->canonicalizeBody($body), true));
        $headerMap = $this->parseHeaders($headers);

        $signedHeaders = [];
        $canonicalizedSignedHeaders = [];

        foreach ($config->headersToSign as $headerName) {
            $normalized = strtolower($headerName);
            if (!array_key_exists($normalized, $headerMap)) {
                continue;
            }

            $value = $headerMap[$normalized];
            $signedHeaders[] = $normalized;
            $canonicalizedSignedHeaders[] = $this->canonicalizer->canonicalizeHeader($normalized, $value);
        }

        if ($signedHeaders === []) {
            throw new RuntimeException('Unable to build DKIM signature: no configured headers found in message.');
        }

        $dkimWithoutSignature = sprintf(
            'v=1; a=rsa-sha256; c=relaxed/relaxed; d=%s; s=%s; t=%d; h=%s; bh=%s; b=',
            $config->domain,
            $config->selector,
            time(),
            implode(':', $signedHeaders),
            $bodyHash,
        );

        $canonicalizedDkimHeader = $this->canonicalizer->canonicalizeHeader('dkim-signature', $dkimWithoutSignature);
        $signingInput = implode("\r\n", [...$canonicalizedSignedHeaders, $canonicalizedDkimHeader]);
        $signature = $this->sign($signingInput, $config->privateKey);

        return 'DKIM-Signature: ' . $dkimWithoutSignature . $signature;
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $headers): array
    {
        $lines = preg_split('/\r\n/', $headers) ?: [];
        $parsed = [];

        foreach ($lines as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower(trim($name))] = ltrim($value);
        }

        return $parsed;
    }

    private function sign(string $input, string $privateKey): string
    {
        $resource = openssl_pkey_get_private($privateKey);

        if ($resource === false) {
            throw new RuntimeException('Invalid DKIM private key.');
        }

        $signature = '';
        $result = openssl_sign($input, $signature, $resource, OPENSSL_ALGO_SHA256);

        if ($result !== true) {
            throw new RuntimeException('Failed to generate DKIM signature.');
        }

        if (!is_string($signature)) {
            throw new RuntimeException('DKIM signer produced a non-string signature.');
        }

        return base64_encode($signature);
    }
}
