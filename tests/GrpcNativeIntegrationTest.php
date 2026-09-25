<?php

declare(strict_types=1);

use Google\Protobuf\GPBEmpty;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;

if (getenv('RUN_GRPC_NATIVE_INTEGRATION') !== '1') {
    return;
}

final class TalkingBytesNativeIntegrationStub extends \Grpc\BaseStub
{
    public function Unary(GPBEmpty $argument, array $metadata = [], array $options = []): object
    {
        return $this->_simpleRequest(
            '/talkingbytes.Integration/Unary',
            $argument,
            [GPBEmpty::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function Server(GPBEmpty $argument, array $metadata = [], array $options = []): object
    {
        return $this->_serverStreamRequest(
            '/talkingbytes.Integration/Server',
            $argument,
            [GPBEmpty::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function Client(array $metadata = [], array $options = []): object
    {
        return $this->_clientStreamRequest(
            '/talkingbytes.Integration/Client',
            [GPBEmpty::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function Bidi(array $metadata = [], array $options = []): object
    {
        return $this->_bidiRequest(
            '/talkingbytes.Integration/Bidi',
            [GPBEmpty::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function SlowServer(GPBEmpty $argument, array $metadata = [], array $options = []): object
    {
        return $this->_serverStreamRequest(
            '/talkingbytes.Integration/SlowServer',
            $argument,
            [GPBEmpty::class, 'decode'],
            $metadata,
            $options,
        );
    }
}

final class TalkingBytesNativeGrpcServer
{
    /** @param array<int, resource|null> $pipes */
    private function __construct(
        private mixed $process,
        private array $pipes,
        private string $directory,
        public readonly int $port,
    ) {}

    public static function start(): self
    {
        $directory = sys_get_temp_dir() . '/tb-grpc-native-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create native gRPC integration directory.');
        }

        $ready = $directory . '/ready.json';
        $python = (string) (getenv('GRPC_PYTHON_BINARY') ?: 'python3');
        $process = proc_open(
            [$python, __DIR__ . '/Fixtures/grpc-native-server.py', $ready],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            rmdir($directory);

            throw new RuntimeException('Unable to start native gRPC integration server.');
        }

        if (is_resource($pipes[0] ?? null)) {
            fclose($pipes[0]);
            $pipes[0] = null;
        }

        $deadline = microtime(true) + 10.0;
        while (!is_file($ready) && microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (($status['running'] ?? false) !== true) {
                $stderr = is_resource($pipes[2] ?? null) ? stream_get_contents($pipes[2]) : '';

                throw new RuntimeException('Native gRPC integration server exited early: ' . trim((string) $stderr));
            }

            usleep(20_000);
        }

        $decoded = is_file($ready) ? json_decode((string) file_get_contents($ready), true) : null;
        $port = is_array($decoded) ? ($decoded['port'] ?? null) : null;
        if (!is_int($port) || $port < 1) {
            throw new RuntimeException('Native gRPC integration server did not become ready.');
        }

        return new self($process, $pipes, $directory, $port);
    }

    public function client(?CancellationSignal $cancellation = null): GrpcClient
    {
        $stub = new TalkingBytesNativeIntegrationStub(
            '127.0.0.1:' . $this->port,
            ['credentials' => \Grpc\ChannelCredentials::createInsecure()],
        );

        return GrpcClient::usingGeneratedStub($stub, cancellation: $cancellation);
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
            if (($status['running'] ?? false) === true) {
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

    public function __destruct()
    {
        $this->stop();
    }
}

it('interoperates with real grpc php call objects for all four call shapes', function (): void {
    expect(extension_loaded('grpc'))->toBeTrue();
    expect(class_exists(\Grpc\BaseStub::class))->toBeTrue();
    expect(class_exists(GPBEmpty::class))->toBeTrue();

    $server = TalkingBytesNativeGrpcServer::start();

    try {
        $client = $server->client();
        $empty = new GPBEmpty();

        $unary = $client->send(new GrpcRequest(
            method: 'Integration/Unary',
            message: $empty,
            deadlineSeconds: 2.0,
        ));
        expect($unary->successful)->toBeTrue();
        expect($unary->response)->toBeInstanceOf(GrpcResponse::class);
        expect($unary->response?->message)->toBeInstanceOf(GPBEmpty::class);
        expect($unary->response?->trailers->first('x-trailer'))->toBe('unary-end');

        $serverMessages = [];
        $serverResult = $client->serverStream(
            new GrpcRequest('Integration/Server', $empty, deadlineSeconds: 2.0),
            static function (mixed $message) use (&$serverMessages): void {
                $serverMessages[] = $message;
            },
        );
        expect($serverResult->successful)->toBeTrue();
        expect($serverMessages)->toHaveCount(2);
        expect($serverMessages[0])->toBeInstanceOf(GPBEmpty::class);
        expect($serverResult->response?->trailers->first('x-trailer'))->toBe('server-end');

        $clientResult = $client->clientStream(
            method: 'Integration/Client',
            messages: [new GPBEmpty(), new GPBEmpty()],
            deadlineSeconds: 2.0,
        );
        expect($clientResult->successful)->toBeTrue();
        expect($clientResult->response?->message)->toBeInstanceOf(GPBEmpty::class);
        expect($clientResult->response?->trailers->first('x-count'))->toBe('2');

        $bidiMessages = [];
        $bidiResult = $client->bidiStream(
            method: 'Integration/Bidi',
            messages: [new GPBEmpty(), new GPBEmpty()],
            onMessage: static function (mixed $message) use (&$bidiMessages): void {
                $bidiMessages[] = $message;
            },
            deadlineSeconds: 2.0,
        );
        expect($bidiResult->successful)->toBeTrue();
        expect($bidiMessages)->toHaveCount(2);
        expect($bidiMessages[0])->toBeInstanceOf(GPBEmpty::class);
        expect($bidiResult->response?->trailers->first('x-count'))->toBe('2');
    } finally {
        $server->stop();
    }
});

it('propagates real native grpc deadlines and cancellation cleanup', function (): void {
    $server = TalkingBytesNativeGrpcServer::start();

    try {
        $deadlineResult = $server->client()->serverStream(
            new GrpcRequest('Integration/SlowServer', new GPBEmpty(), deadlineSeconds: 0.05),
            static function (mixed $message): void {
                unset($message);
            },
        );
        expect($deadlineResult->successful)->toBeFalse();
        expect($deadlineResult->statusCode)->toBe(4);

        $cancelled = false;
        $messages = 0;
        $signal = CancellationSignal::fromCallable(static fn(): bool => $cancelled);
        $cancelResult = $server->client($signal)->serverStream(
            new GrpcRequest('Integration/Server', new GPBEmpty(), deadlineSeconds: 2.0),
            static function (mixed $message) use (&$cancelled, &$messages): void {
                unset($message);
                $messages++;
                $cancelled = true;
            },
        );

        expect($cancelResult->successful)->toBeFalse();
        expect($cancelResult->error)->toContain('cancelled');
        expect($messages)->toBe(1);
    } finally {
        $server->stop();
    }
});
