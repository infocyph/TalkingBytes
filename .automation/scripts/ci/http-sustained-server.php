<?php

declare(strict_types=1);

$expectedRequests = (int) ($argv[1] ?? 0);
$delayMicroseconds = (int) ($argv[2] ?? 0);
$readyPath = $argv[3] ?? '';

if ($expectedRequests < 1 || $delayMicroseconds < 0 || $readyPath === '') {
    throw new InvalidArgumentException('Invalid sustained HTTP server arguments.');
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    throw new RuntimeException(sprintf('Unable to bind sustained HTTP server: %s (%d)', $errstr, $errno));
}

stream_set_blocking($server, false);
$address = stream_socket_get_name($server, false);
if (!is_string($address) || !str_contains($address, ':')) {
    fclose($server);

    throw new RuntimeException('Unable to resolve sustained HTTP server address.');
}

$port = (int) substr(strrchr($address, ':'), 1);
file_put_contents($readyPath, json_encode(['port' => $port], JSON_THROW_ON_ERROR));

/** @var array<int, array{stream:resource,buffer:string,due:?float}> $clients */
$clients = [];
$completed = 0;
$deadline = microtime(true) + 30.0;

$withoutStreamWarnings = static function (callable $operation): mixed {
    set_error_handler(
        static fn(): bool => true,
        E_NOTICE | E_WARNING,
    );

    try {
        return $operation();
    } finally {
        restore_error_handler();
    }
};

while ($completed < $expectedRequests && microtime(true) < $deadline) {
    $read = [$server];
    foreach ($clients as $client) {
        if ($client['due'] === null) {
            $read[] = $client['stream'];
        }
    }

    $write = [];
    $except = [];
    $withoutStreamWarnings(
        static function () use (&$read, &$write, &$except): int|false {
            return stream_select($read, $write, $except, 0, 5_000);
        },
    );

    foreach ($read as $stream) {
        if ($stream === $server) {
            while (($client = $withoutStreamWarnings(
                static fn(): mixed => stream_socket_accept($server, 0),
            )) !== false) {
                stream_set_blocking($client, false);
                $clients[(int) $client] = [
                    'stream' => $client,
                    'buffer' => '',
                    'due' => null,
                ];
            }

            continue;
        }

        $id = (int) $stream;
        if (!isset($clients[$id])) {
            continue;
        }

        $chunk = $withoutStreamWarnings(
            static fn(): string|false => fread($stream, 8192),
        );
        if (!is_string($chunk) || $chunk === '') {
            continue;
        }

        $clients[$id]['buffer'] .= $chunk;
        if ($clients[$id]['due'] === null && str_contains($clients[$id]['buffer'], "\r\n\r\n")) {
            $clients[$id]['due'] = microtime(true) + ($delayMicroseconds / 1_000_000);
        }
    }

    $now = microtime(true);
    foreach ($clients as $id => $client) {
        if ($client['due'] === null || $client['due'] > $now) {
            continue;
        }

        $body = 'ok';
        $response = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/plain\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;

        $withoutStreamWarnings(
            static fn(): int|false => fwrite($client['stream'], $response),
        );
        fclose($client['stream']);
        unset($clients[$id]);
        $completed++;
    }
}

foreach ($clients as $client) {
    fclose($client['stream']);
}
fclose($server);

if ($completed !== $expectedRequests) {
    throw new RuntimeException(sprintf(
        'Sustained HTTP server completed %d of %d expected requests.',
        $completed,
        $expectedRequests,
    ));
}
