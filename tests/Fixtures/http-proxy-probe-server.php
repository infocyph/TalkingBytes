<?php

declare(strict_types=1);

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
file_put_contents($readyPath, json_encode(['port' => $port], JSON_THROW_ON_ERROR));

$requests = 0;
$deadline = microtime(true) + 8.0;
while (microtime(true) < $deadline) {
    $client = @stream_socket_accept($server, 0);
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
    file_put_contents($reportPath, json_encode(['requests' => $requests], JSON_THROW_ON_ERROR));
}
fclose($server);
file_put_contents($reportPath, json_encode(['requests' => $requests], JSON_THROW_ON_ERROR));
