<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use Infocyph\TalkingBytes\Email\Enum\DkimAlgorithm;
use Infocyph\TalkingBytes\Email\Exception\DkimException;

final readonly class DkimSigner
{
    private Clock $clock;

    public function __construct(
        private DkimCanonicalizer $canonicalizer = new DkimCanonicalizer(),
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? Clock::system();
    }

    public function buildSignatureHeader(string $headers, string $body, DkimConfig $config): string
    {
        $bodyHash = base64_encode(hash(
            'sha256',
            $this->canonicalizer->canonicalizeBody($body, $config->bodyCanonicalization),
            true,
        ));
        $headerMap = $this->parseHeaders($headers);

        $signedHeaders = [];
        $canonicalizedSignedHeaders = [];

        foreach ($config->headersToSign as $headerName) {
            $normalized = strtolower($headerName);
            if (!array_key_exists($normalized, $headerMap)) {
                continue;
            }

            $field = array_pop($headerMap[$normalized]);
            if (!is_array($field)) {
                continue;
            }
            $value = $field['value'];
            $signedHeaders[] = $normalized;
            $canonicalizedSignedHeaders[] = $this->canonicalizer->canonicalizeHeader(
                $config->headerCanonicalization === 'simple' ? $field['name'] : $normalized,
                $value,
                $config->headerCanonicalization,
            );
        }

        if ($signedHeaders === []) {
            throw new DkimException('Unable to build DKIM signature: no configured headers found in message.');
        }

        $dkimWithoutSignature = sprintf(
            'v=1; a=%s; c=%s/%s; d=%s; s=%s; t=%d; h=%s; bh=%s; b=',
            $config->algorithm->value,
            $config->headerCanonicalization,
            $config->bodyCanonicalization,
            $config->domain,
            $config->selector,
            (int) floor($this->clock->timestamp()),
            implode(':', $signedHeaders),
            $bodyHash,
        );

        $canonicalizedDkimHeader = $this->canonicalizer->canonicalizeHeader(
            $config->headerCanonicalization === 'simple' ? 'DKIM-Signature' : 'dkim-signature',
            $config->headerCanonicalization === 'simple' ? ' ' . $dkimWithoutSignature : $dkimWithoutSignature,
            $config->headerCanonicalization,
        );
        $signingInput = implode("\r\n", [...$canonicalizedSignedHeaders, $canonicalizedDkimHeader]);
        $signature = $this->sign($signingInput, $config);

        return 'DKIM-Signature: ' . $dkimWithoutSignature . $signature;
    }

    /**
     * @return array<string, list<array{name:string,value:string}>>
     */
    private function parseHeaders(string $headers): array
    {
        $parsed = [];

        foreach ($this->rawHeaderFields($headers) as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $normalizedName = strtolower(trim($name));

            if (!array_key_exists($normalizedName, $parsed)) {
                $parsed[$normalizedName] = [];
            }

            $parsed[$normalizedName][] = ['name' => trim($name), 'value' => $value];
        }

        return $parsed;
    }

    /** @return list<string> */
    private function rawHeaderFields(string $headers): array
    {
        $fields = [];
        foreach (preg_split('/\r\n/', $headers) ?: [] as $line) {
            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                $index = array_key_last($fields);
                if ($index !== null) {
                    $fields[$index] .= "\r\n" . $line;
                }

                continue;
            }
            $fields[] = $line;
        }

        return $fields;
    }

    private function sign(string $input, DkimConfig $config): string
    {
        if ($config->algorithm === DkimAlgorithm::Ed25519Sha256) {
            return $this->signEd25519($input, $config->privateKey);
        }

        $resource = openssl_pkey_get_private($config->privateKey);

        if ($resource === false) {
            throw new DkimException('Invalid DKIM private key.');
        }

        $signature = '';
        $result = openssl_sign($input, $signature, $resource, OPENSSL_ALGO_SHA256);

        if (!$result) {
            throw new DkimException('Failed to generate DKIM signature.');
        }

        if (!is_string($signature)) {
            throw new DkimException('DKIM signer produced a non-string signature.');
        }

        return base64_encode($signature);
    }

    private function signEd25519(string $input, string $encodedKey): string
    {
        $key = base64_decode(trim($encodedKey), true);
        if (!is_string($key)) {
            throw new DkimException('Invalid Ed25519 DKIM private key encoding.');
        }
        if (strlen($key) === 32) {
            $keyPair = sodium_crypto_sign_seed_keypair($key);
            $key = sodium_crypto_sign_secretkey($keyPair);
        }
        if (strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new DkimException('Invalid Ed25519 DKIM private key length.');
        }

        return base64_encode(sodium_crypto_sign_detached($input, $key));
    }
}
