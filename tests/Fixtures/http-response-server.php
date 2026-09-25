<?php

declare(strict_types=1);

$readyPath = $argv[1] ?? '';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    throw new RuntimeException(sprintf('Unable to bind HTTP response fixture: %s (%d)', $errstr, $errno));
}

$address = stream_socket_get_name($server, false);
if (!is_string($address) || !str_contains($address, ':')) {
    fclose($server);
    throw new RuntimeException('Unable to resolve HTTP response fixture address.');
}

$port = (int) substr(strrchr($address, ':'), 1);
file_put_contents($readyPath, json_encode(['port' => $port], JSON_THROW_ON_ERROR));

$deadline = microtime(true) + 5.0;
$handled = 0;
while ($handled < 32 && microtime(true) < $deadline) {
    set_error_handler(static fn(): bool => true, E_WARNING);
    try {
        $client = stream_socket_accept($server, 0.1);
    } finally {
        restore_error_handler();
    }
    if ($client === false) {
        continue;
    }

    $buffer = '';
    while (!str_contains($buffer, "\r\n\r\n") && !feof($client)) {
        $chunk = fread($client, 8192);
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        $buffer .= $chunk;
    }

    $requestLine = strtok($buffer, "\r\n") ?: '';
    $path = '/';
    if (preg_match('/^[A-Z]+\s+(\S+)\s+HTTP\//', $requestLine, $m) === 1) {
        $parsed = parse_url($m[1], PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : '/';
    }

    $hostHeader = '';
    $cookieHeader = '';
    foreach (preg_split('/\r\n/', $buffer) ?: [] as $line) {
        if (str_starts_with(strtolower($line), 'host:')) {
            $hostHeader = trim(substr($line, 5));
        }
        if (str_starts_with(strtolower($line), 'cookie:')) {
            $cookieHeader = trim(substr($line, 7));
        }
    }

    $status = 200;
    $body = '';
    $headers = [];
    if ($path === '/204') {
        $status = 204;
    } elseif ($path === '/304') {
        $status = 304;
    } elseif ($path === '/redirect') {
        $status = 302;
        $headers['Location'] = sprintf('http://127.0.0.1:%d/zero', $port);
    } elseif ($path === '/download-redirect-success') {
        $status = 302;
        $body = 'REDIRECT-BODY';
        $headers['Location'] = sprintf('http://127.0.0.1:%d/download-final', $port);
    } elseif ($path === '/download-redirect-error') {
        $status = 302;
        $body = 'REDIRECT-BODY';
        $headers['Location'] = sprintf('http://127.0.0.1:%d/error', $port);
    } elseif ($path === '/download-loop') {
        $status = 302;
        $body = 'REDIRECT-BODY';
        $headers['Location'] = sprintf('http://127.0.0.1:%d/download-loop', $port);
    } elseif ($path === '/download-blocked') {
        $status = 302;
        $body = 'REDIRECT-BODY';
        $headers['Location'] = sprintf('http://localhost:%d/download-final', $port);
    } elseif ($path === '/download-final') {
        $body = 'FINAL-BODY';
    } elseif ($path === '/cookie-chain-start') {
        $status = 302;
        $headers['Set-Cookie'] = 'origin_cookie=origin-value; Path=/';
        $headers['Location'] = sprintf('http://localhost:%d/cookie-chain-target', $port);
    } elseif ($path === '/cookie-chain-target') {
        $status = 302;
        $headers['Set-Cookie'] = 'target_cookie=target-value; Path=/';
        $headers['Location'] = sprintf('http://127.0.0.1:%d/cookie-chain-return', $port);
    } elseif ($path === '/cookie-chain-return' || $path === '/cookie-echo') {
        $body = $cookieHeader;
    } elseif ($path === '/host-echo') {
        $body = $hostHeader;
    } elseif ($path === '/error') {
        $status = 500;
        $body = 'failure';
    }

    $reason = match ($status) {
        204 => 'No Content',
        302 => 'Found',
        304 => 'Not Modified',
        500 => 'Internal Server Error',
        default => 'OK',
    };

    $wire = sprintf("HTTP/1.1 %d %s\r\n", $status, $reason);
    foreach ($headers as $name => $value) {
        $wire .= $name . ': ' . $value . "\r\n";
    }
    $wire .= 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    fwrite($client, $wire);
    fclose($client);
    $handled++;
}

fclose($server);
