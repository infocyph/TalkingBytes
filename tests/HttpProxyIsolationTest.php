<?php

declare(strict_types=1);

/**
 * @param resource $process
 * @param array<int, resource|null> $pipes
 */
function waitForHttpProxyProbePort(mixed $process, array $pipes, string $ready): int
{
    $deadline = hrtime(true) + 2_000_000_000;
    while (hrtime(true) < $deadline) {
        $status = proc_get_status($process);
        $decoded = is_file($ready) ? json_decode((string) file_get_contents($ready), true) : null;
        $port = is_array($decoded) ? ($decoded['port'] ?? null) : null;
        if (is_int($port) && $port > 0 && $port <= 65535) {
            return $port;
        }
        if (!$status['running']) {
            break;
        }
        usleep(10_000);
    }

    $error = '';
    if (is_resource($pipes[2] ?? null)) {
        stream_set_blocking($pipes[2], false);
        $error = trim((string) stream_get_contents($pipes[2]));
    }

    throw new RuntimeException($error === ''
        ? 'Proxy probe did not publish a valid ready port.'
        : 'Proxy probe failed to start: ' . $error);
}

/**
 * @param resource|null $process
 * @param array<int, resource|null> $pipes
 */
function stopHttpProxyProbe(mixed $process, array $pipes, string $directory): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($process)) {
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process);
        }
        proc_close($process);
    }
    foreach (glob($directory . '/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}

it('disables inherited proxy environment for strict single and multi http transports', function (): void {
    $directory = sys_get_temp_dir() . '/tb-http-proxy-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $ready = $directory . '/ready.json';
    $report = $directory . '/report.json';

    $proxy = null;
    $proxyPipes = [];

    try {
        $proxy = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/http-proxy-probe-server.php', $ready, $report],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $proxyPipes,
        );
        expect($proxy)->toBeResource();

        if (is_resource($proxyPipes[0] ?? null)) {
            fclose($proxyPipes[0]);
            $proxyPipes[0] = null;
        }

        $port = waitForHttpProxyProbePort($proxy, $proxyPipes, $ready);
        expect($port)->toBeInt();

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $proxyUrl = sprintf('http://127.0.0.1:%d', $port);
        foreach (['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy'] as $name) {
            $environment[$name] = $proxyUrl;
        }
        $environment['NO_PROXY'] = '';
        $environment['no_proxy'] = '';

        foreach (['single', 'multi'] as $mode) {
            $output = $directory . '/' . $mode . '.json';
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/Fixtures/http-strict-proxy-client.php', $mode, $output],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $environment,
            );
            expect($process)->toBeResource();

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            expect(proc_close($process))->toBe(0);

            $result = json_decode((string) file_get_contents($output), true);
            expect($result['successful'] ?? null)->toBeFalse();
        }

        usleep(100_000);
        expect(is_file($report))->toBeTrue();
        $probe = json_decode((string) file_get_contents($report), true);
        expect($probe)->toBeArray()
            ->and($probe['requests'] ?? null)->toBe(0)
            ->and(proc_get_status($proxy)['running'])->toBeTrue();
    } finally {
        stopHttpProxyProbe($proxy, $proxyPipes, $directory);
    }
});

it('waits for a complete proxy readiness payload', function (string $partial): void {
    $directory = sys_get_temp_dir() . '/tb-proxy-partial-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $ready = $directory . '/ready.json';
    file_put_contents($ready, $partial);
    $process = null;
    $pipes = [];

    try {
        $process = proc_open(
            [PHP_BINARY, '-r', 'usleep(250000); file_put_contents($argv[1], "{\"port\":12345}");', $ready],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        expect($process)->toBeResource();
        expect(waitForHttpProxyProbePort($process, $pipes, $ready))->toBe(12345);
    } finally {
        stopHttpProxyProbe($process, $pipes, $directory);
    }
})->with([
    'empty file' => [''],
    'partial JSON' => ['{"port":'],
]);

it('reports proxy fixture startup errors without waiting for missing readiness', function (): void {
    $directory = sys_get_temp_dir() . '/tb-proxy-startup-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $process = null;
    $pipes = [];

    try {
        $process = proc_open(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "proxy fixture startup failed deliberately"); exit(7);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        expect($process)->toBeResource();
        expect(fn() => waitForHttpProxyProbePort($process, $pipes, $directory . '/ready.json'))
            ->toThrow(RuntimeException::class, 'proxy fixture startup failed deliberately');
    } finally {
        stopHttpProxyProbe($process, $pipes, $directory);
    }

    expect(is_dir($directory))->toBeFalse();
});
