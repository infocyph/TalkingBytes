<?php

declare(strict_types=1);

it('confines Runwire dependencies to the optional integration boundary', function (): void {
    $root = dirname(__DIR__);
    $allowed = [
        'src/Email/EmailMailboxFactory.php',
        'src/Email/EmailReceiverFactory.php',
        'src/Email/EmailSenderFactory.php',
        'src/Grpc/GrpcClientFactory.php',
        'src/Http/Concurrent/RequestPool.php',
        'src/Http/HttpClientFactory.php',
        'src/Integration/Runwire/RunwireBinding.php',
    ];

    $references = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if (!is_string($contents) || !str_contains($contents, 'Infocyph\\Runwire\\')) {
            continue;
        }

        $references[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    }

    sort($references);

    expect($references)->toBe($allowed);
});

it('keeps host lifecycle ownership out of the Runwire adapter', function (): void {
    $adapter = file_get_contents(dirname(__DIR__) . '/src/Integration/Runwire/RunwireBinding.php');

    expect($adapter)->toBeString()
        ->not->toContain('CoroutineRuntime')
        ->not->toContain('attachRequest(')
        ->not->toContain('->complete(')
        ->not->toContain('->close(')
        ->not->toContain('pcntl_signal')
        ->not->toContain('pcntl_fork');
});
