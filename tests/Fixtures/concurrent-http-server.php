<?php

declare(strict_types=1);

$scenarioPath = $argv[1] ?? '';
$readyPath = $argv[2] ?? '';
$reportPath = $argv[3] ?? '';

$decoded = json_decode((string) file_get_contents($scenarioPath), true);
if (!is_array($decoded)) {
    exit(1);
}

$delays = [];
foreach ($decoded as $path => $delayMs) {
    if (is_string($path) && is_int($delayMs)) {
        $delays[$path] = $delayMs;
    }
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    file_put_contents($reportPath, json_encode(['error' => sprintf('%s (%d)', $errstr, $errno)]));
    exit(1);
}

stream_set_blocking($server, false);
$address = stream_socket_get_name($server, false);
if (!is_string($address) || !str_contains($address, ':')) {
    fclose($server);
    exit(1);
}

$port = (int) substr(strrchr($address, ':'), 1);
file_put_contents($readyPath, json_encode(['port' => $port], JSON_THROW_ON_ERROR));

$startedAt = microtime(true);
$deadline = $startedAt + 5.0;
$expected = count($delays);
$completed = 0;
$requestTimes = [];

/**
 * @var array<int, array{stream:resource, buffer:string, path:?string, due:?float}> $clients
 */
$clients = [];

while ($completed < $expected && microtime(true) < $deadline) {
    $read = [$server];
    foreach ($clients as $client) {
        if ($client['path'] === null) {
            $read[] = $client['stream'];
        }
    }

    $write = [];
    $except = [];
    @stream_select($read, $write, $except, 0, 10_000);

    foreach ($read as $stream) {
        if ($stream === $server) {
            while (($client = @stream_socket_accept($server, 0)) !== false) {
                stream_set_blocking($client, false);
                $clients[(int) $client] = [
                    'stream' => $client,
                    'buffer' => '',
                    'path' => null,
                    'due' => null,
                ];
            }

            continue;
        }

        $id = (int) $stream;
        if (!isset($clients[$id])) {
            continue;
        }

        $chunk = fread($stream, 8192);
        if (!is_string($chunk) || $chunk === '') {
            continue;
        }

        $clients[$id]['buffer'] .= $chunk;
        if ($clients[$id]['path'] !== null || !str_contains($clients[$id]['buffer'], "\r\n\r\n")) {
            continue;
        }

        if (preg_match('/^[A-Z]+\\s+(\\S+)\\s+HTTP\\/\\d(?:\\.\\d)?/D', $clients[$id]['buffer'], $matches) !== 1) {
            continue;
        }

        $path = parse_url($matches[1], PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $delayMs = $delays[$path] ?? 0;

        $clients[$id]['path'] = $path;
        $clients[$id]['due'] = microtime(true) + ($delayMs / 1000);
        $requestTimes[$path] = (int) round((microtime(true) - $startedAt) * 1000);
    }

    $now = microtime(true);
    foreach ($clients as $id => $client) {
        if ($client['due'] === null || $client['due'] > $now) {
            continue;
        }

        $body = $client['path'] ?? '/';
        $response = "HTTP/1.1 200 OK\r\n"
            . 'Content-Type: text/plain' . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . 'Connection: close' . "\r\n\r\n"
            . $body;

        @fwrite($client['stream'], $response);
        fclose($client['stream']);
        unset($clients[$id]);
        $completed++;
    }
}

foreach ($clients as $client) {
    fclose($client['stream']);
}

fclose($server);
file_put_contents($reportPath, json_encode([
    'request_times' => $requestTimes,
    'completed' => $completed,
], JSON_THROW_ON_ERROR));
