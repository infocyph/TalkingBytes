<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\Enum\DkimAlgorithm;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Webhook\Webhook;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;

function talkingBytesOptionalColdnessEnabled(): bool
{
    return getenv('TALKINGBYTES_OPTIONAL_COLDNESS') === '1';
}

function talkingBytesColdnessRsaPrivateKey(): string
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);

    if ($key === false) {
        throw new RuntimeException('Unable to generate RSA key for optional-capability coldness test.');
    }

    $privateKey = '';
    if (!openssl_pkey_export($key, $privateKey)) {
        throw new RuntimeException('Unable to export RSA key for optional-capability coldness test.');
    }

    return $privateKey;
}

it('keeps unrelated protocol graphs usable without optional runtime extensions', function (): void {
    if (!talkingBytesOptionalColdnessEnabled()) {
        expect(true)->toBeTrue();

        return;
    }

    foreach (['grpc', 'imap', 'posix'] as $extension) {
        expect(extension_loaded($extension))->toBeFalse();
    }

    expect(class_exists('Grpc\\Channel'))->toBeFalse();

    $http = HttpClient::using(new FakeHttpTransport());
    expect($http->send(HttpRequest::get('https://example.test/health'))->successful)->toBeTrue();

    $webhook = Webhook::sender($http)->send(
        WebhookMessage::new('runtime.coldness')
            ->url('https://example.test/webhook')
            ->payload(['ok' => true]),
    );
    expect($webhook->result->successful)->toBeTrue();

    $email = Email::sender()->usingNull();
    expect($email->send(
        EmailMessage::new()
            ->from('sender@example.test')
            ->to('recipient@example.test')
            ->subject('Cold runtime')
            ->text('ok'),
    )->successful)->toBeTrue();

    $smtp = Email::sender()->usingSmtp(new SmtpConfig('smtp.example.test'));
    expect($smtp)->toBeInstanceOf(Emailer::class);

    $grpc = GrpcClient::using(
        static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(
            GrpcStatus::Ok,
            $request->message,
        ),
    );
    $grpcResult = $grpc->send(new GrpcRequest('/runtime.v1.Health/Check', ['ok' => true]));

    expect($grpcResult->successful)->toBeTrue()
        ->and(class_exists('Grpc\\Channel'))->toBeFalse();
});

it('keeps RSA DKIM independent from Sodium', function (): void {
    if (!talkingBytesOptionalColdnessEnabled()) {
        expect(true)->toBeTrue();

        return;
    }

    $config = DkimConfig::fromPrivateKeyString(
        'example.test',
        'selector',
        talkingBytesColdnessRsaPrivateKey(),
    );

    expect($config->algorithm)->toBe(DkimAlgorithm::RsaSha256);
});

it('fails Ed25519 DKIM clearly only when the capability is selected', function (): void {
    if (!talkingBytesOptionalColdnessEnabled()) {
        expect(true)->toBeTrue();

        return;
    }

    $build = static fn(): DkimConfig => DkimConfig::fromPrivateKeyString(
        'example.test',
        'selector',
        base64_encode(random_bytes(32)),
        algorithm: DkimAlgorithm::Ed25519Sha256,
    );

    if (!function_exists('sodium_crypto_sign_detached')) {
        expect($build)->toThrow(RuntimeException::class, 'Sodium extension is required for Ed25519 DKIM signing.');

        return;
    }

    expect($build()->algorithm)->toBe(DkimAlgorithm::Ed25519Sha256);
});


it('confines compiled-in optional capabilities to their selected feature boundary', function (): void {
    $root = dirname(__DIR__) . '/src';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    $pcntlReferences = [];
    $unexpectedSodiumReferences = [];
    $allowedSodiumFiles = [
        'Email/Config/DkimConfig.php',
        'Email/Dkim/DkimSigner.php',
        'Email/Dkim/DkimVerifier.php',
        'Email/Dkim/DkimPublicKeyParser.php',
    ];

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if (!is_string($contents)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (preg_match('/\\bpcntl_/', $contents) === 1) {
            $pcntlReferences[] = $relative;
        }

        if (
            preg_match('/\\bsodium_/', $contents) === 1
            && !in_array($relative, $allowedSodiumFiles, true)
        ) {
            $unexpectedSodiumReferences[] = $relative;
        }
    }

    expect($pcntlReferences)->toBe([])
        ->and($unexpectedSodiumReferences)->toBe([]);
});
