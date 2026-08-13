<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use Infocyph\TalkingBytes\Email\Dkim\DkimSigner;
use Infocyph\TalkingBytes\Email\Dkim\DkimVerifier;
use Infocyph\TalkingBytes\Email\Dkim\StaticDkimPublicKeyResolver;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Parser\AuthenticationResultsParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;

function inboundAuthBuildKeyPair(): array
{
    $configPath = sys_get_temp_dir().'/tb-openssl-inbound-'.bin2hex(random_bytes(6)).'.cnf';
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

        throw new RuntimeException('Unable to generate inbound DKIM key pair.');
    }

    $privateKey = '';
    if (! openssl_pkey_export($resource, $privateKey, null, ['config' => $configPath])) {
        unlink($configPath);

        throw new RuntimeException('Unable to export inbound DKIM private key.');
    }

    $details = openssl_pkey_get_details($resource);
    if (! is_array($details) || ! is_string($details['key'] ?? null)) {
        unlink($configPath);

        throw new RuntimeException('Unable to export inbound DKIM public key.');
    }

    unlink($configPath);

    return [$privateKey, $details['key']];
}

function inboundAuthPublicKeyRecordFromPem(string $publicKeyPem): string
{
    $value = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $publicKeyPem);

    return 'v=DKIM1; k=rsa; p='.$value;
}

it('parses authentication-results and exposes trust helpers', function (): void {
    $header = 'mx.example.com; spf=pass smtp.mailfrom=sender.example; dkim=pass header.d=example.com header.s=s1; dmarc=pass header.from=example.com; arc=none';

    $results = (new AuthenticationResultsParser)->parse($header);

    expect($results->authservId)->toBe('mx.example.com');
    expect($results->passedSpf())->toBeTrue();
    expect($results->passedDkim())->toBeTrue();
    expect($results->passedDmarc())->toBeTrue();
    expect($results->passedArc())->toBeFalse();
    expect($results->isAuthenticated())->toBeTrue();

    $dkim = $results->check('dkim');
    expect($dkim)->not->toBeNull();
    expect($dkim?->domain)->toBe('example.com');
    expect($dkim?->selector)->toBe('s1');
});

it('verifies inbound dkim signatures using resolver abstraction', function (): void {
    [$privateKey, $publicKey] = inboundAuthBuildKeyPair();

    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('user@example.net')
        ->subject('Inbound verify')
        ->text('Hello DKIM');

    $raw = (new RawEmailBuilder)->build($message);
    $config = new DkimConfig('example.com', 's1', $privateKey);
    $signatureLine = (new DkimSigner)->buildSignatureHeader($raw->headers, $raw->body, $config);

    $signedRaw = $signatureLine."\r\n".$raw->headers."\r\n\r\n".$raw->body;
    $parsed = (new RawEmailParser)->parse($signedRaw);

    $resolver = new StaticDkimPublicKeyResolver([
        's1._domainkey.example.com' => inboundAuthPublicKeyRecordFromPem($publicKey),
    ]);

    $result = (new DkimVerifier($resolver))->verify($parsed);

    expect($result->valid)->toBeTrue();
    expect($result->domain)->toBe('example.com');
    expect($result->selector)->toBe('s1');
});

it('verifies wire-exact simple dkim header and body canonicalization', function (): void {
    [$privateKey, $publicKey] = inboundAuthBuildKeyPair();
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('user@example.net')
        ->subject('Simple canonicalization')
        ->text("Line one \t\r\nLine two");

    $raw = (new RawEmailBuilder())->build($message);
    $config = new DkimConfig(
        'example.com',
        's1',
        $privateKey,
        headerCanonicalization: 'simple',
        bodyCanonicalization: 'simple',
    );
    $signatureLine = (new DkimSigner())->buildSignatureHeader($raw->headers, $raw->body, $config);
    $parsed = (new RawEmailParser())->parse($signatureLine . "\r\n" . $raw->headers . "\r\n\r\n" . $raw->body);
    $resolver = new StaticDkimPublicKeyResolver([
        's1._domainkey.example.com' => inboundAuthPublicKeyRecordFromPem($publicKey),
    ]);

    expect((new DkimVerifier($resolver))->verify($parsed)->valid)->toBeTrue();
});

it('rejects unsupported dkim canonicalization before key lookup', function (): void {
    $raw = implode("\r\n", [
        'DKIM-Signature: v=1; a=rsa-sha256; c=unknown/simple; d=example.com; s=s1; h=from; bh=abc; b=abc',
        'From: sender@example.com',
        '',
        'Body',
    ]);
    $parsed = (new RawEmailParser())->parse($raw);
    $resolver = new StaticDkimPublicKeyResolver([]);

    expect((new DkimVerifier($resolver))->verify($parsed)->reason)
        ->toBe('Unsupported DKIM canonicalization.');
});

it('fails dkim verification when key resolver returns wrong public key', function (): void {
    [$privateKey] = inboundAuthBuildKeyPair();
    [, $otherPublicKey] = inboundAuthBuildKeyPair();

    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('user@example.net')
        ->subject('Inbound verify fail')
        ->text('Hello DKIM fail');

    $raw = (new RawEmailBuilder)->build($message);
    $config = new DkimConfig('example.com', 's1', $privateKey);
    $signatureLine = (new DkimSigner)->buildSignatureHeader($raw->headers, $raw->body, $config);

    $signedRaw = $signatureLine."\r\n".$raw->headers."\r\n\r\n".$raw->body;
    $parsed = (new RawEmailParser)->parse($signedRaw);

    $resolver = new StaticDkimPublicKeyResolver([
        's1._domainkey.example.com' => inboundAuthPublicKeyRecordFromPem($otherPublicKey),
    ]);

    $result = (new DkimVerifier($resolver))->verify($parsed);

    expect($result->valid)->toBeFalse();
    expect($result->reason)->toContain('verification failed');
});

it('parses complex authentication-results segments and malformed tokens tolerantly', function (): void {
    $header = 'mx.example.com; dkim=pass header.d=example.com header.s=s1 reason="ok"; spf=pass smtp.mailfrom=sender@example.com; dmarc=pass header.from=example.com; arc=pass; malformed-segment';

    $results = (new AuthenticationResultsParser)->parse($header);

    expect($results->authservId)->toBe('mx.example.com');
    expect($results->passedDkim())->toBeTrue();
    expect($results->passedSpf())->toBeTrue();
    expect($results->passedDmarc())->toBeTrue();
    expect($results->passedArc())->toBeTrue();
    expect($results->isAuthenticated())->toBeTrue();
    expect($results->resultFor('dkim')?->properties['reason'] ?? null)->toBe('ok');
    expect($results->check('spf')?->scope)->toBe('sender@example.com');
});

it('verifies only the last dkim-signature header and fails on invalid trailing signature', function (): void {
    [$privateKey, $publicKey] = inboundAuthBuildKeyPair();

    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('user@example.net')
        ->subject('DKIM multi')
        ->text('Body');

    $raw = (new RawEmailBuilder)->build($message);
    $signatureLine = (new DkimSigner)->buildSignatureHeader($raw->headers, $raw->body, new DkimConfig('example.com', 's1', $privateKey));

    $invalidLine = preg_replace('/\bb=[^;]*/', 'b=not-base64-***', $signatureLine, 1);
    expect($invalidLine)->toBeString();

    $signedRaw = $signatureLine."\r\n".(string) $invalidLine."\r\n".$raw->headers."\r\n\r\n".$raw->body;
    $parsed = (new RawEmailParser)->parse($signedRaw);
    $resolver = new StaticDkimPublicKeyResolver([
        's1._domainkey.example.com' => inboundAuthPublicKeyRecordFromPem($publicKey),
    ]);

    $result = (new DkimVerifier($resolver))->verify($parsed);

    expect($result->valid)->toBeFalse();
    expect($result->reason)->toContain('verification failed');
});

it('fails dkim verification when resolver returns revoked key', function (): void {
    [$privateKey] = inboundAuthBuildKeyPair();

    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('user@example.net')
        ->subject('DKIM revoked key')
        ->text("Line 1\nLine 2");

    $raw = (new RawEmailBuilder)->build($message);
    $signatureLine = (new DkimSigner)->buildSignatureHeader($raw->headers, $raw->body, new DkimConfig('example.com', 's1', $privateKey));
    $signedRaw = str_replace("\r\n", "\n", $signatureLine."\r\n".$raw->headers."\r\n\r\n".$raw->body);

    $parsed = (new RawEmailParser)->parse($signedRaw);
    $resolver = new StaticDkimPublicKeyResolver([
        's1._domainkey.example.com' => 'v=DKIM1; p=',
    ]);

    $result = (new DkimVerifier($resolver))->verify($parsed);

    expect($result->valid)->toBeFalse();
    expect($result->reason)->toContain('public key record is invalid');
});
