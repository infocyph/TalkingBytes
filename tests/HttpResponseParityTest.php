<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Http\Concurrent\CurlMultiTransport;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Transport\CurlTransport;

final class HttpResponseParityServer
{
    /** @param array<int, resource|null> $pipes */
    private function __construct(
        private mixed $process,
        private array $pipes,
        private string $directory,
        public int $port,
    ) {}

    public function __destruct()
    {
        $this->stop();
    }

    public static function start(): self
    {
        $directory = sys_get_temp_dir() . '/tb-http-response-' . bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $ready = $directory . '/ready.json';

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/http-response-server.php', $ready],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start HTTP response fixture.');
        }

        if (is_resource($pipes[0] ?? null)) {
            fclose($pipes[0]);
            $pipes[0] = null;
        }

        $deadline = microtime(true) + 2.0;
        while (!is_file($ready) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $decoded = is_file($ready) ? json_decode((string) file_get_contents($ready), true) : null;
        $port = is_array($decoded) ? ($decoded['port'] ?? null) : null;
        if (!is_int($port) || $port < 1) {
            throw new RuntimeException('HTTP response fixture did not become ready.');
        }

        return new self($process, $pipes, $directory, $port);
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

        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }
}

it('accepts valid bodyless responses and follows empty redirects', function (): void {
    $server = HttpResponseParityServer::start();

    try {
        $base = sprintf('http://127.0.0.1:%d', $server->port);
        $transport = new CurlTransport();

        foreach ([['/zero', 200], ['/204', 204], ['/304', 304]] as [$path, $status]) {
            $result = $transport->send(HttpRequest::get($base . $path));
            expect($result->successful)->toBeTrue();
            expect($result->statusCode)->toBe($status);
            expect($result->response?->body)->toBe('');

            $multi = (new CurlMultiTransport())->sendMany([
                'request' => HttpRequest::get($base . $path),
            ])->get('request');
            expect($multi?->successful)->toBeTrue();
            expect($multi?->statusCode)->toBe($status);
            expect($multi?->response?->body)->toBe('');
        }

        $redirected = $transport->send(
            HttpRequest::get($base . '/redirect')->followRedirects(),
        );
        expect($redirected->successful)->toBeTrue();
        expect($redirected->statusCode)->toBe(200);
        expect($redirected->response?->body)->toBe('');
    } finally {
        $server->stop();
    }
});

it('preserves existing download targets on HTTP failure', function (): void {
    $server = HttpResponseParityServer::start();
    $target = sys_get_temp_dir() . '/tb-http-existing-' . bin2hex(random_bytes(6)) . '.txt';
    file_put_contents($target, 'existing');

    try {
        $base = sprintf('http://127.0.0.1:%d', $server->port);

        $buffered = (new CurlTransport())->send(
            HttpRequest::get($base . '/error')->downloadTo($target),
        );
        expect($buffered->successful)->toBeFalse();
        expect(file_get_contents($target))->toBe('existing');

        $streamed = (new CurlTransport())->send(
            HttpRequest::get($base . '/error')->streamDownloadTo($target),
        );
        expect($streamed->successful)->toBeFalse();
        expect(file_get_contents($target))->toBe('existing');
    } finally {
        if (is_file($target)) {
            unlink($target);
        }
        $server->stop();
    }
});
