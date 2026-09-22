<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Http\Concurrent\CurlMultiTransport;
use Infocyph\TalkingBytes\Http\Concurrent\PoolResult;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Transport\CurlTransport;


final class ConcurrentHttpTestServer
{
    /**
     * @param array<int, resource|null> $pipes
     */
    private function __construct(
        private mixed $process,
        private array $pipes,
        private string $workDir,
        public int $port,
    ) {}

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * @param array<string, int> $delays
     */
    public static function start(array $delays): self
    {
        $workDir = sys_get_temp_dir() . '/talkingbytes-http-multi-' . bin2hex(random_bytes(6));
        mkdir($workDir, 0775, true);

        $scenarioPath = $workDir . '/scenario.json';
        $readyPath = $workDir . '/ready.json';
        $reportPath = $workDir . '/report.json';
        file_put_contents($scenarioPath, json_encode($delays, JSON_THROW_ON_ERROR));

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/concurrent-http-server.php', $scenarioPath, $readyPath, $reportPath],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!is_resource($process)) {
            self::cleanupDirectory($workDir);

            throw new RuntimeException('Unable to start concurrent HTTP test server.');
        }

        if (is_resource($pipes[0] ?? null)) {
            fclose($pipes[0]);
            $pipes[0] = null;
        }

        $port = self::waitForReadyPort($process, $pipes, $readyPath);

        return new self($process, $pipes, $workDir, $port);
    }

    /**
     * @return array<string, int>
     */
    public function requestTimes(): array
    {
        $reportPath = $this->workDir . '/report.json';
        $deadline = microtime(true) + 1.0;

        while (!is_file($reportPath) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $decoded = is_file($reportPath)
            ? json_decode((string) file_get_contents($reportPath), true)
            : null;
        $times = is_array($decoded) ? ($decoded['request_times'] ?? []) : [];

        if (!is_array($times)) {
            return [];
        }

        $normalized = [];
        foreach ($times as $path => $milliseconds) {
            if (is_string($path) && is_int($milliseconds)) {
                $normalized[$path] = $milliseconds;
            }
        }

        return $normalized;
    }

    public function stop(): void
    {
        foreach ([1, 2] as $index) {
            if (is_resource($this->pipes[$index] ?? null)) {
                fclose($this->pipes[$index]);
                $this->pipes[$index] = null;
            }
        }

        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process);
            }

            proc_close($this->process);
            $this->process = null;
        }

        self::cleanupDirectory($this->workDir);
    }

    private static function cleanupDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    /**
     * @param array<int, resource|null> $pipes
     */
    private static function waitForReadyPort(mixed $process, array $pipes, string $readyPath): int
    {
        $deadline = microtime(true) + 2.0;

        while (microtime(true) < $deadline) {
            if (is_file($readyPath)) {
                $decoded = json_decode((string) file_get_contents($readyPath), true);
                $port = is_array($decoded) ? ($decoded['port'] ?? null) : null;
                if (is_int($port) && $port > 0) {
                    return $port;
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            usleep(10_000);
        }

        $stderr = is_resource($pipes[2] ?? null) ? stream_get_contents($pipes[2]) : '';

        throw new RuntimeException('Concurrent HTTP test server did not start: ' . $stderr);
    }
}

it('preserves request keys in concurrent pool results', function (): void {
    $requests = [
        'users' => HttpRequest::get('https://example.com/users')->blockHosts(['example.com']),
        'orders' => HttpRequest::get('https://example.com/orders')->blockHosts(['example.com']),
    ];

    $pool = (new CurlMultiTransport())->sendMany($requests, maxConcurrency: 10, stopOnFailure: false);

    expect(array_keys($pool->all()))->toBe(['users', 'orders']);
    expect($pool->get('users'))->toBeInstanceOf(CommunicationResult::class);
    expect($pool->get('orders'))->toBeInstanceOf(CommunicationResult::class);
});

it('supports pool result helper methods', function (): void {
    $pool = new PoolResult([
        'ok' => CommunicationResult::success(statusCode: 200),
        'bad' => CommunicationResult::failure('boom', statusCode: 500),
    ]);

    expect($pool->successfulCount())->toBe(1);
    expect($pool->failedCount())->toBe(1);
    expect(array_keys($pool->successful()))->toBe(['ok']);
    expect(array_keys($pool->failed()))->toBe(['bad']);
    expect($pool->firstError()?->error)->toBe('boom');
    expect($pool->get('missing'))->toBeNull();
});

it('stops scheduling new work after a failure when enabled', function (): void {
    $poolClient = HttpClient::multi(maxConcurrency: 1)->stopSchedulingOnFailure();
    $requests = [
        'first' => HttpRequest::get('https://example.com/first')->blockHosts(['example.com']),
        'second' => HttpRequest::get('https://example.com/second')->blockHosts(['example.com']),
        'third' => HttpRequest::get('https://example.com/third')->blockHosts(['example.com']),
    ];

    $result = $poolClient->sendMany($requests);

    expect(array_keys($result->all()))->toBe(['first']);
    expect($result->metadata['stopped_scheduling'] ?? null)->toBeTrue();
});

it('uses the same request configuration path in single and multi transports', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'tb-upload-');
    expect($path)->toBeString();
    file_put_contents($path, 'payload');

    $request = HttpRequest::post('https://example.com/upload')
        ->uploadFromFile($path)
        ->raw('body');

    $singleResult = (new CurlTransport())->send($request);
    $multiResult = (new CurlMultiTransport())->sendMany(['x' => $request])->get('x');

    if (is_file($path)) {
        unlink($path);
    }

    expect($singleResult->successful)->toBeFalse();
    expect($multiResult)->toBeInstanceOf(CommunicationResult::class);
    expect($multiResult?->successful)->toBeFalse();
    expect($singleResult->error)->toContain('cannot combine uploadFromFile/uploadFromStream');
    expect($multiResult?->error)->toContain('cannot combine uploadFromFile/uploadFromStream');
});


it('stops admitting new requests immediately after a preparation failure', function (): void {
    $pool = HttpClient::multi(maxConcurrency: 3)->stopSchedulingOnFailure();

    $result = $pool->sendMany([
        'first' => HttpRequest::get('https://example.com/first')->blockHosts(['example.com']),
        'second' => HttpRequest::get('https://example.com/second')->blockHosts(['example.com']),
        'third' => HttpRequest::get('https://example.com/third')->blockHosts(['example.com']),
    ]);

    expect(array_keys($result->all()))->toBe(['first']);
    expect($result->metadata['stopped_scheduling'] ?? null)->toBeTrue();
    expect($result->metadata['cancelled'] ?? null)->toBeFalse();
});

it('returns deterministic cancelled results before pool scheduling starts', function (): void {
    $pool = HttpClient::multi(maxConcurrency: 2)
        ->withCancellation(CancellationSignal::fromCallable(static fn(): bool => true));

    $result = $pool->sendMany([
        'first' => HttpRequest::get('https://example.com/first'),
        'second' => HttpRequest::get('https://example.com/second'),
        'third' => HttpRequest::get('https://example.com/third'),
    ]);

    expect(array_keys($result->all()))->toBe(['first', 'second', 'third']);
    expect($result->metadata['stopped_scheduling'] ?? null)->toBeTrue();
    expect($result->metadata['cancelled'] ?? null)->toBeTrue();

    foreach ($result->all() as $cancelled) {
        expect($cancelled->successful)->toBeFalse();
        expect($cancelled->metadata['cancelled'] ?? null)->toBeTrue();
        expect($cancelled->metadata['started'] ?? null)->toBeFalse();
    }
});


it('refills the rolling window as soon as a fast request completes', function (): void {
    $server = ConcurrentHttpTestServer::start([
        '/slow' => 700,
        '/fast-one' => 100,
        '/fast-two' => 10,
    ]);

    try {
        $baseUrl = sprintf('http://127.0.0.1:%d', $server->port);
        $result = HttpClient::multi(maxConcurrency: 2)->sendMany([
            'slow' => HttpRequest::get($baseUrl . '/slow'),
            'fast-one' => HttpRequest::get($baseUrl . '/fast-one'),
            'fast-two' => HttpRequest::get($baseUrl . '/fast-two'),
        ]);

        expect($result->successfulCount())->toBe(3);
        expect(array_keys($result->all()))->toBe(['slow', 'fast-one', 'fast-two']);

        $times = $server->requestTimes();
        expect($times)->toHaveKeys(['/slow', '/fast-one', '/fast-two']);
        expect($times['/fast-two'] - $times['/slow'])->toBeLessThan(350);
    } finally {
        $server->stop();
    }
});

it('cancels active curl handles cooperatively', function (): void {
    $server = ConcurrentHttpTestServer::start(['/slow' => 1000]);

    try {
        $baseUrl = sprintf('http://127.0.0.1:%d', $server->port);
        $startedAt = microtime(true);
        $cancellation = CancellationSignal::fromCallable(
            static fn(): bool => (microtime(true) - $startedAt) >= 0.1,
        );

        $result = HttpClient::multi(maxConcurrency: 1, cancellation: $cancellation)->sendMany([
            'slow' => HttpRequest::get($baseUrl . '/slow'),
        ]);

        $cancelled = $result->get('slow');
        expect($cancelled)->toBeInstanceOf(CommunicationResult::class);
        expect($cancelled?->successful)->toBeFalse();
        expect($cancelled?->metadata['cancelled'] ?? null)->toBeTrue();
        expect($cancelled?->metadata['started'] ?? null)->toBeTrue();
        expect($result->metadata['cancelled'] ?? null)->toBeTrue();
    } finally {
        $server->stop();
    }
});
