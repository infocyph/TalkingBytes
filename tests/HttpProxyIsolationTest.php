<?php

declare(strict_types=1);

it('disables inherited proxy environment for strict single and multi http transports', function (): void {
    $directory = sys_get_temp_dir() . '/tb-http-proxy-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $ready = $directory . '/ready.json';
    $report = $directory . '/report.json';

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

    $deadline = microtime(true) + 2.0;
    while (!is_file($ready) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    $decoded = is_file($ready) ? json_decode((string) file_get_contents($ready), true) : null;
    $port = is_array($decoded) ? ($decoded['port'] ?? null) : null;
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

    try {
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
        $probe = is_file($report) ? json_decode((string) file_get_contents($report), true) : null;
        expect(is_array($probe) ? ($probe['requests'] ?? 0) : 0)->toBe(0);
    } finally {
        foreach ([1, 2] as $index) {
            if (is_resource($proxyPipes[$index] ?? null)) {
                fclose($proxyPipes[$index]);
            }
        }
        if (is_resource($proxy)) {
            $status = proc_get_status($proxy);
            if ($status['running']) {
                proc_terminate($proxy);
            }
            proc_close($proxy);
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
});
