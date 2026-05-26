<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use Infocyph\TalkingBytes\Email\Dkim\DkimCanonicalizer;
use Infocyph\TalkingBytes\Email\Dkim\DkimSigner;
use Infocyph\TalkingBytes\Email\Dkim\DnsDkimPublicKeyResolver;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\DkimAlgorithm;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;
use Infocyph\TalkingBytes\Email\Testing\FakeEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\DkimSigningTransport;

function dkimBuildKeyPair(): array
{
    $configPath = sys_get_temp_dir().'/tb-openssl-'.bin2hex(random_bytes(6)).'.cnf';
    file_put_contents(
        $configPath,
        "openssl_conf = openssl_init\n\n[openssl_init]\nproviders = provider_sect\n\n[provider_sect]\ndefault = default_sect\n\n[default_sect]\nactivate = 1\n",
    );

    $resource = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 1024,
        'config' => $configPath,
    ]);

    if ($resource === false) {
        unlink($configPath);

        throw new RuntimeException('Unable to generate DKIM test key pair.');
    }

    $privateKey = '';
    if (! openssl_pkey_export($resource, $privateKey, null, ['config' => $configPath])) {
        unlink($configPath);

        throw new RuntimeException('Unable to export DKIM private key.');
    }

    $details = openssl_pkey_get_details($resource);
    if (! is_array($details) || ! is_string($details['key'] ?? null)) {
        unlink($configPath);

        throw new RuntimeException('Unable to export DKIM public key.');
    }

    unlink($configPath);

    return [$privateKey, $details['key']];
}

/**
 * @return array<string, string>
 */
function dkimParseTags(string $value): array
{
    $tags = [];
    foreach (explode(';', $value) as $part) {
        $part = trim($part);
        if ($part === '' || ! str_contains($part, '=')) {
            continue;
        }

        [$name, $tagValue] = explode('=', $part, 2);
        $tags[trim($name)] = trim($tagValue);
    }

    return $tags;
}

/**
 * @return list<string>
 */
function dkimUnfoldedHeaders(string $headers): array
{
    $lines = preg_split('/\r\n/', $headers) ?: [];
    $result = [];

    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }

        if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
            $last = array_key_last($result);
            if ($last !== null) {
                $result[$last] .= ' '.ltrim($line);
            }

            continue;
        }

        $result[] = $line;
    }

    return $result;
}

function dkimFindHeaderValue(string $headers, string $name): ?string
{
    $needle = strtolower($name).':';
    $matches = [];

    foreach (dkimUnfoldedHeaders($headers) as $line) {
        if (! str_starts_with(strtolower($line), $needle)) {
            continue;
        }

        $matches[] = ltrim(substr($line, strlen($needle)));
    }

    if ($matches === []) {
        return null;
    }

    return $matches[array_key_last($matches)];
}

it('generates a verifiable DKIM signature and matching body hash', function (): void {
    [$privateKey, $publicKey] = dkimBuildKeyPair();

    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('DKIM')
        ->text("Line 1\r\nLine 2");

    $raw = (new RawEmailBuilder)->build($message, includeSubject: true);
    $config = new DkimConfig('example.com', 'selector', $privateKey);

    $signer = new DkimSigner;
    $headerLine = $signer->buildSignatureHeader($raw->headers, $raw->body, $config);
    $headerValue = trim(substr($headerLine, strlen('DKIM-Signature:')));
    $tags = dkimParseTags($headerValue);

    expect($tags)->toHaveKeys(['h', 'bh', 'b', 'd', 's']);

    $canonicalizer = new DkimCanonicalizer;
    $expectedBh = base64_encode(hash('sha256', $canonicalizer->canonicalizeBody($raw->body), true));
    expect($tags['bh'])->toBe($expectedBh);

    $signedHeaders = array_filter(array_map('trim', explode(':', strtolower($tags['h']))));
    $canonicalized = [];

    foreach ($signedHeaders as $headerName) {
        $value = dkimFindHeaderValue($raw->headers, $headerName);
        expect($value)->not->toBeNull();

        $canonicalized[] = $canonicalizer->canonicalizeHeader($headerName, (string) $value);
    }

    $withoutSignatureValue = preg_replace('/\bb=[^;]*/', 'b=', $headerValue, 1);
    expect($withoutSignatureValue)->not->toBeNull();
    $canonicalized[] = $canonicalizer->canonicalizeHeader('dkim-signature', (string) $withoutSignatureValue);

    $verificationPayload = implode("\r\n", $canonicalized);
    $signatureBinary = base64_decode($tags['b'], true);
    expect($signatureBinary)->not->toBeFalse();

    $verified = openssl_verify($verificationPayload, $signatureBinary, $publicKey, OPENSSL_ALGO_SHA256);
    expect($verified)->toBe(1);
});

it('adds dkim-signature header via signing transport decorator', function (): void {
    [$privateKey] = dkimBuildKeyPair();

    $inner = new FakeEmailTransport;
    $transport = new DkimSigningTransport($inner, new DkimConfig('example.com', 'selector', $privateKey));

    $result = $transport->send(
        EmailMessage::new()
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Decorated')
            ->text('Body'),
    );

    expect($result->successful)->toBeTrue();
    $sent = $inner->sentMessages();
    expect($sent)->toHaveCount(1);
    expect($sent[0]->headersData()->customHeaders)->toHaveKey('DKIM-Signature');
    expect((string) $sent[0]->headersData()->customHeaders['DKIM-Signature'])->toContain('v=1');
});

it('builds dkim config from private key helpers', function (): void {
    [$privateKey] = dkimBuildKeyPair();
    $path = sys_get_temp_dir().'/tb-dkim-key-'.bin2hex(random_bytes(4)).'.pem';
    file_put_contents($path, $privateKey);

    $fromPath = DkimConfig::fromPrivateKeyPath('example.com', 'selector', $path);
    $fromString = DkimConfig::fromPrivateKeyString('example.com', 'selector', $privateKey);

    expect($fromPath->privateKey)->toBe($privateKey);
    expect($fromString->privateKey)->toBe($privateKey);

    unlink($path);
});

it('keeps dkim algorithm extensible and rejects unsupported signer algorithms', function (): void {
    [$privateKey] = dkimBuildKeyPair();

    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('DKIM Alg')
        ->text('Body');

    $raw = (new RawEmailBuilder)->build($message, includeSubject: true);
    $config = DkimConfig::fromPrivateKeyString(
        'example.com',
        'selector',
        $privateKey,
        algorithm: DkimAlgorithm::Ed25519Sha256,
    );

    expect(fn () => (new DkimSigner)->buildSignatureHeader($raw->headers, $raw->body, $config))
        ->toThrow(RuntimeException::class, 'Unsupported DKIM algorithm');
});

it('resolves dkim txt records with split entries and ignores unrelated records', function (): void {
    $resolver = new DnsDkimPublicKeyResolver(static function (string $name, int $type): array {
        expect($name)->toBe('selector._domainkey.example.com');
        expect($type)->toBe(DNS_TXT);

        return [
            ['txt' => 'google-site-verification=abc123'],
            ['entries' => ['v=DKIM1; k=rsa; ', 'p=abcDEF123']],
        ];
    });

    $record = $resolver->resolve('example.com', 'selector');

    expect($record)->toBe('v=DKIM1; k=rsa; p=abcDEF123');
});

it('treats dkim txt records with empty p tag as revoked', function (): void {
    $resolver = new DnsDkimPublicKeyResolver(static function (string $name, int $type): array {
        expect($name)->toBe('selector._domainkey.example.com');
        expect($type)->toBe(DNS_TXT);

        return [
            ['txt' => 'v=DKIM1; p='],
        ];
    });

    expect($resolver->resolve('example.com', 'selector'))->toBeNull();
});
