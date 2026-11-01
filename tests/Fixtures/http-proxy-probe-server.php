<?php

declare(strict_types=1);

/** @param array<string, int> $state */
function publishProxyProbeState(string $path, array $state): void
{
    $payload = json_encode($state, JSON_THROW_ON_ERROR);
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, $payload) !== strlen($payload) || !rename($temporary, $path)) {
        throw new RuntimeException('Unable to publish proxy probe state.');
    }
}

$readyPath = $argv[1] ?? '';
$reportPath = $argv[2] ?? '';

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    throw new RuntimeException(sprintf('Unable to bind proxy probe: %s (%d)', $errstr, $errno));
}
stream_set_blocking($server, false);

$address = stream_socket_get_name($server, false);
if (!is_string($address) || !str_contains($address, ':')) {
    fclose($server);

    throw new RuntimeException('Unable to resolve proxy probe address.');
}
$port = (int) substr(strrchr($address, ':'), 1);
publishProxyProbeState($reportPath, ['requests' => 0]);
publishProxyProbeState($readyPath, ['port' => $port]);

$requests = 0;
$deadline = microtime(true) + 8.0;
while (microtime(true) < $deadline) {
    set_error_handler(static fn(): bool => true, E_WARNING);

    try {
        $client = stream_socket_accept($server, 0);
    } finally {
        restore_error_handler();
    }
    if ($client === false) {
        usleep(10_000);

        continue;
    }

    $requests++;
    $buffer = '';
    while (!str_contains($buffer, "\r\n\r\n") && !feof($client)) {
        $chunk = fread($client, 8192);
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        $buffer .= $chunk;
    }

    $body = 'proxy-hit';
    fwrite(
        $client,
        "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body)
        . "\r\nConnection: close\r\n\r\n" . $body,
    );
    fclose($client);
    publishProxyProbeState($reportPath, ['requests' => $requests]);
}
fclose($server);
publishProxyProbeState($reportPath, ['requests' => $requests]);
