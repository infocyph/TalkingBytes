<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infocyph\TalkingBytes\Http\Concurrent\CurlMultiTransport;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Transport\CurlTransport;

$mode = $argv[1] ?? 'single';
$output = $argv[2] ?? '';
$request = HttpRequest::get('http://93.184.216.34:1/')
    ->blockPrivateNetworks()
    ->connectTimeout(1)
    ->timeout(1);

if ($mode === 'multi') {
    $result = (new CurlMultiTransport())->sendMany(['probe' => $request])->get('probe');
} else {
    $result = (new CurlTransport())->send($request);
}

file_put_contents($output, json_encode([
    'successful' => $result?->successful,
    'status' => $result?->statusCode,
    'error' => $result?->error,
], JSON_THROW_ON_ERROR));
