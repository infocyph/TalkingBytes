<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use ReflectionMethod;
use RuntimeException;

if (getenv('RUN_GRPC_NATIVE_INTEGRATION') !== '1') {
    return;
}

final class TalkingBytesNativeIntegrationStub
{
    private object $stub;

    public function __construct(string $target)
    {
        $baseStub = 'Grpc\\BaseStub';
        $credentials = 'Grpc\\ChannelCredentials';

        $this->stub = new $baseStub(
            $target,
            ['credentials' => $credentials::createInsecure()],
        );
    }

    public function Bidi(array $metadata = [], array $options = []): object
    {
        return $this->invokeBase('_bidiRequest', [
            '/talkingbytes.Integration/Bidi',
            ['Google\\Protobuf\\GPBEmpty', 'decode'],
            $metadata,
            $options,
        ]);
    }

    public function Client(array $metadata = [], array $options = []): object
    {
        return $this->invokeBase('_clientStreamRequest', [
            '/talkingbytes.Integration/Client',
            ['Google\\Protobuf\\GPBEmpty', 'decode'],
            $metadata,
            $options,
        ]);
    }

    public function Server(mixed $argument, array $metadata = [], array $options = []): object
    {
        return $this->invokeBase('_serverStreamRequest', [
            '/talkingbytes.Integration/Server',
            $argument,
            ['Google\\Protobuf\\GPBEmpty', 'decode'],
            $metadata,
            $options,
        ]);
    }

    public function SlowServer(mixed $argument, array $metadata = [], array $options = []): object
    {
        return $this->invokeBase('_serverStreamRequest', [
            '/talkingbytes.Integration/SlowServer',
            $argument,
            ['Google\\Protobuf\\GPBEmpty', 'decode'],
            $metadata,
            $options,
        ]);
    }

    public function Unary(mixed $argument, array $metadata = [], array $options = []): object
    {
        return $this->invokeBase('_simpleRequest', [
            '/talkingbytes.Integration/Unary',
            $argument,
            ['Google\\Protobuf\\GPBEmpty', 'decode'],
            $metadata,
            $options,
        ]);
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invokeBase(string $method, array $arguments): object
    {
        $result = (new ReflectionMethod($this->stub, $method))->invokeArgs($this->stub, $arguments);
        if (!is_object($result)) {
            throw new RuntimeException(sprintf('Native gRPC stub method "%s" did not return a call object.', $method));
        }

        return $result;
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

    public function __destruct()
    {
        $this->stop();
    }

    public function client(?CancellationSignal $cancellation = null): GrpcClient
    {
        return GrpcClient::usingGeneratedStub(
            new TalkingBytesNativeIntegrationStub('127.0.0.1:' . $this->port),
            cancellation: $cancellation,
        );
    }

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
}

function talkingBytesNativeEmptyMessage(): object
{
    $class = 'Google\\Protobuf\\GPBEmpty';

    return new $class();
}

it('interoperates with real grpc php call objects for all four call shapes', function (): void {
    $emptyClass = 'Google\\Protobuf\\GPBEmpty';

    expect(extension_loaded('grpc'))->toBeTrue();
    expect(class_exists('Grpc\\BaseStub'))->toBeTrue();
    expect(class_exists($emptyClass))->toBeTrue();

    $server = TalkingBytesNativeGrpcServer::start();

    try {
        $client = $server->client();
        $empty = talkingBytesNativeEmptyMessage();

        $unary = $client->send(new GrpcRequest(
            method: 'Integration/Unary',
            message: $empty,
            deadlineSeconds: 2.0,
        ));
        expect($unary->successful)->toBeTrue();
        expect($unary->response)->toBeInstanceOf(GrpcResponse::class);
        expect($unary->response?->message)->toBeInstanceOf($emptyClass);
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
        expect($serverMessages[0])->toBeInstanceOf($emptyClass);
        expect($serverResult->response?->trailers->first('x-trailer'))->toBe('server-end');

        $clientResult = $client->clientStream(
            method: 'Integration/Client',
            messages: [talkingBytesNativeEmptyMessage(), talkingBytesNativeEmptyMessage()],
            deadlineSeconds: 2.0,
        );
        expect($clientResult->successful)->toBeTrue();
        expect($clientResult->response?->message)->toBeInstanceOf($emptyClass);
        expect($clientResult->response?->trailers->first('x-count'))->toBe('2');

        $bidiMessages = [];
        $bidiResult = $client->bidiStream(
            method: 'Integration/Bidi',
            messages: [talkingBytesNativeEmptyMessage(), talkingBytesNativeEmptyMessage()],
            onMessage: static function (mixed $message) use (&$bidiMessages): void {
                $bidiMessages[] = $message;
            },
            deadlineSeconds: 2.0,
        );
        expect($bidiResult->successful)->toBeTrue();
        expect($bidiMessages)->toHaveCount(2);
        expect($bidiMessages[0])->toBeInstanceOf($emptyClass);
        expect($bidiResult->response?->trailers->first('x-count'))->toBe('2');
    } finally {
        $server->stop();
    }
});

it('propagates real native grpc deadlines and cancellation cleanup', function (): void {
    $server = TalkingBytesNativeGrpcServer::start();

    try {
        $deadlineResult = $server->client()->serverStream(
            new GrpcRequest('Integration/SlowServer', talkingBytesNativeEmptyMessage(), deadlineSeconds: 0.05),
            static function (mixed $message): void {
                unset($message);
            },
        );
        expect($deadlineResult->successful)->toBeFalse();
        expect($deadlineResult->statusCode)->toBe(4);

        $cancelled = false;
        $messages = 0;
        $signal = CancellationSignal::fromCallable(static function () use (&$cancelled): bool {
            return $cancelled;
        });
        $cancelResult = $server->client($signal)->serverStream(
            new GrpcRequest('Integration/Server', talkingBytesNativeEmptyMessage(), deadlineSeconds: 2.0),
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
