<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\Native\GeneratedStubGrpcInvoker;

it('adapts generated grpc stub unary and stream calls', function (): void {
    $stub = new class {
        /** @var list<array<string, mixed>> */
        public array $captures = [];

        public function Chat(array $metadata = [], array $options = []): object
        {
            $this->captures[] = ['method' => 'Chat', 'metadata' => $metadata, 'options' => $options];

            return new class {
                /** @var list<mixed> */
                private array $written = [];

                public function closeWrite(): void {}

                public function getMetadata(): array
                {
                    return ['x-header' => ['chat']];
                }

                public function getTrailingMetadata(): array
                {
                    return ['x-trailer' => ['chat-end']];
                }

                public function read(): ?array
                {
                    static $messages = [['reply' => 1], ['reply' => 2]];
                    if ($messages === []) {
                        return null;
                    }

                    return array_shift($messages);
                }

                public function wait(): array
                {
                    return [['done' => true], ['code' => 0]];
                }

                public function write(mixed $message): void
                {
                    $this->written[] = $message;
                }
            };
        }

        public function Create(mixed $message, array $metadata = [], array $options = []): object
        {
            $this->captures[] = [
                'method' => 'Create',
                'message' => $message,
                'metadata' => $metadata,
                'options' => $options,
            ];

            return new class {
                public function getMetadata(): array
                {
                    return ['x-header' => ['create']];
                }

                public function getTrailingMetadata(): array
                {
                    return ['x-trailer' => ['create-end']];
                }

                public function wait(): array
                {
                    return [['id' => 1001], ['code' => 0]];
                }
            };
        }

        public function List(mixed $message, array $metadata = [], array $options = []): object
        {
            $this->captures[] = [
                'method' => 'List',
                'message' => $message,
                'metadata' => $metadata,
                'options' => $options,
            ];

            return new class {
                public function getMetadata(): array
                {
                    return ['x-header' => ['list']];
                }

                public function getTrailingMetadata(): array
                {
                    return ['x-trailer' => ['list-end']];
                }

                public function responses(): array
                {
                    return [['row' => 1], ['row' => 2]];
                }

                public function wait(): array
                {
                    return [null, ['code' => 0]];
                }
            };
        }

        public function Upload(array $metadata = [], array $options = []): object
        {
            $this->captures[] = ['method' => 'Upload', 'metadata' => $metadata, 'options' => $options];

            return new class {
                public function getMetadata(): array
                {
                    return ['x-header' => ['upload']];
                }

                public function getTrailingMetadata(): array
                {
                    return ['x-trailer' => ['upload-end']];
                }

                public function wait(): array
                {
                    return [['uploaded' => true], ['code' => 0]];
                }

                public function writesDone(): void {}

                public function write(mixed $message): void {}
            };
        }
    };

    $adapter = new GeneratedStubGrpcInvoker($stub);
    $client = GrpcClient::usingNativeStreaming($adapter, $adapter);

    $unary = $client->send(new GrpcRequest(
        method: 'Orders/Create',
        message: ['name' => 'A'],
        headers: (new GrpcMetadata())->withValue('x-request-id', 'req-1'),
        deadlineSeconds: 1.5,
    ));

    $serverChunks = [];
    $server = $client->serverStream(
        new GrpcRequest('Orders/List', ['page' => 1], deadlineSeconds: 2.0),
        static function (mixed $message) use (&$serverChunks): void {
            $serverChunks[] = $message;
        },
    );

    $clientOnly = $client->clientStream(
        method: 'Orders/Upload',
        messages: [['id' => 1], ['id' => 2]],
        headers: (new GrpcMetadata())->withValue('x-upload', 'yes'),
    );

    $bidiChunks = [];
    $bidi = $client->bidiStream(
        method: 'Orders/Chat',
        messages: [['a' => 1], ['a' => 2]],
        onMessage: static function (mixed $message) use (&$bidiChunks): void {
            $bidiChunks[] = $message;
        },
    );

    expect($unary->successful)->toBeTrue()
        ->and($unary->response)->toBeInstanceOf(GrpcResponse::class)
        ->and($unary->response->message['id'])->toBe(1001)
        ->and($unary->response->trailers->first('x-trailer'))->toBe('create-end')
        ->and($server->successful)->toBeTrue()
        ->and($serverChunks)->toHaveCount(2)
        ->and($clientOnly->successful)->toBeTrue()
        ->and($bidi->successful)->toBeTrue()
        ->and($bidiChunks)->toHaveCount(2)
        ->and($stub->captures[0]['options']['timeout'])->toBe(1_500_000);
});

it('uses method map when grpc method path differs from stub method name', function (): void {
    $stub = new class {
        /** @var list<array<string, mixed>> */
        public array $captures = [];

        public function CreateOrder(mixed $message, array $metadata = [], array $options = []): object
        {
            $this->captures[] = [
                'method' => 'CreateOrder',
                'message' => $message,
                'metadata' => $metadata,
                'options' => $options,
            ];

            return new class {
                public function wait(): array
                {
                    return [['ok' => true], ['code' => 0]];
                }
            };
        }
    };

    $adapter = new GeneratedStubGrpcInvoker($stub, [
        '/orders.v1.OrderService/Create' => 'CreateOrder',
    ]);

    $client = GrpcClient::usingNative($adapter);
    $result = $client->send(new GrpcRequest('/orders.v1.OrderService/Create', ['id' => 7]));

    expect($result->successful)->toBeTrue()
        ->and($result->response->message['ok'])->toBeTrue();
});


it('does not reinvoke a stream method when the stub throws an internal type error', function (): void {
    $stub = new class {
        public int $calls = 0;

        public function Upload(array $metadata = [], array $options = []): object
        {
            unset($metadata, $options);
            $this->calls++;

            throw new TypeError('internal generated stub type error');
        }
    };

    $client = GrpcClient::usingGeneratedStub($stub);
    $result = $client->clientStream(
        method: 'Orders/Upload',
        messages: [['id' => 1]],
    );

    expect($result->successful)->toBeFalse();
    expect($stub->calls)->toBe(1);
    expect($result->error)->toContain('internal generated stub type error');
});

it('resolves three argument stream open shape before invocation', function (): void {
    $stub = new class {
        /** @var list<array<string, mixed>> */
        public array $captures = [];

        public function Upload(mixed $message = null, array $metadata = [], array $options = []): object
        {
            $this->captures[] = [
                'message' => $message,
                'metadata' => $metadata,
                'options' => $options,
            ];

            return new class {
                public function wait(): array
                {
                    return [['ok' => true], ['code' => 0]];
                }

                public function writesDone(): void {}

                public function write(mixed $message): void
                {
                    unset($message);
                }
            };
        }
    };

    $result = GrpcClient::usingGeneratedStub($stub)->clientStream(
        method: 'Orders/Upload',
        messages: [['id' => 1]],
        headers: (new GrpcMetadata())->withValue('x-upload', 'yes'),
        deadlineSeconds: 1.0,
    );

    expect($result->successful)->toBeTrue();
    expect($stub->captures)->toHaveCount(1);
    expect($stub->captures[0]['message'])->toBeNull();
    expect($stub->captures[0]['metadata']['x-upload'][0] ?? null)->toBe('yes');
    expect($stub->captures[0]['options']['timeout'] ?? null)->toBe(1_000_000);
});

it('validates explicit generated method maps at adapter construction', function (): void {
    $stub = new class {
        public function Create(mixed $message, array $metadata = [], array $options = []): object
        {
            unset($message, $metadata, $options);

            return new class {
                public function wait(): array
                {
                    return [['ok' => true], ['code' => 0]];
                }
            };
        }
    };

    expect(fn() => new GeneratedStubGrpcInvoker($stub, [
        '/orders.v1.OrderService/Create' => 'Missing',
    ]))->toThrow(InvalidArgumentException::class, 'was not found');

    expect(fn() => new GeneratedStubGrpcInvoker($stub, [
        ' orders.v1.OrderService/Create' => 'Create',
    ]))->toThrow(InvalidArgumentException::class, 'surrounding whitespace');
});

it('cancels generated client streams between outbound writes', function (): void {
    $state = (object) ['requested' => false];

    $stub = new class($state) {
        public ?object $call = null;

        public function __construct(private object $state) {}

        public function Upload(array $metadata = [], array $options = []): object
        {
            unset($metadata, $options);
            $state = $this->state;

            return $this->call = new class($state) {
                public bool $cancelled = false;

                public int $writes = 0;

                public function __construct(private object $state) {}

                public function cancel(): void
                {
                    $this->cancelled = true;
                }

                public function wait(): array
                {
                    return [['ok' => true], ['code' => 0]];
                }

                public function writesDone(): void {}

                public function write(mixed $message): void
                {
                    unset($message);
                    $this->writes++;
                    $this->state->requested = true;
                }
            };
        }
    };

    $signal = CancellationSignal::fromCallable(static fn(): bool => $state->requested);
    $result = GrpcClient::usingGeneratedStub($stub, cancellation: $signal)->clientStream(
        method: 'Orders/Upload',
        messages: [['id' => 1], ['id' => 2]],
    );

    expect($result->successful)->toBeFalse();
    expect($stub->call)->not->toBeNull();
    expect($stub->call?->writes)->toBe(1);
    expect($stub->call?->cancelled)->toBeTrue();
    expect($result->error)->toContain('cancelled');
});

it('cancels the native stream when a response callback fails', function (): void {
    $stub = new class {
        public ?object $call = null;

        public function List(mixed $message, array $metadata = [], array $options = []): object
        {
            unset($message, $metadata, $options);

            return $this->call = new class {
                public bool $cancelled = false;

                public function cancel(): void
                {
                    $this->cancelled = true;
                }

                public function responses(): iterable
                {
                    yield ['row' => 1];
                    yield ['row' => 2];
                }

                public function wait(): array
                {
                    return [null, ['code' => 0]];
                }
            };
        }
    };

    $result = GrpcClient::usingGeneratedStub($stub)->serverStream(
        new GrpcRequest('Orders/List', ['page' => 1]),
        static function (mixed $message): void {
            unset($message);

            throw new RuntimeException('consumer callback failed');
        },
    );

    expect($result->successful)->toBeFalse();
    expect($stub->call?->cancelled)->toBeTrue();
    expect($result->error)->toContain('consumer callback failed');
});


it('finalizes upstream-shaped server and bidi streams with getStatus', function (): void {
    $stub = new class {
        public function List(mixed $message, array $metadata = [], array $options = []): object
        {
            unset($message, $metadata, $options);

            return new class {
                public function getMetadata(): array
                {
                    return ['x-header' => ['list']];
                }

                public function getStatus(): object
                {
                    return (object) ['code' => 0, 'details' => 'ok'];
                }

                public function getTrailingMetadata(): array
                {
                    return ['x-trailer' => ['list-end']];
                }

                public function responses(): iterable
                {
                    yield ['row' => 1];
                    yield ['row' => 2];
                }
            };
        }

        public function Chat(array $metadata = [], array $options = []): object
        {
            unset($metadata, $options);

            return new class {
                private int $read = 0;

                public function closeWrite(): void {}

                public function getStatus(): object
                {
                    return (object) ['code' => 0, 'details' => 'complete'];
                }

                public function read(): ?array
                {
                    $this->read++;

                    return $this->read <= 2 ? ['reply' => $this->read] : null;
                }

                public function write(mixed $message): void
                {
                    unset($message);
                }
            };
        }
    };

    $client = GrpcClient::usingGeneratedStub($stub);

    $serverChunks = [];
    $server = $client->serverStream(
        new GrpcRequest('Orders/List', ['page' => 1]),
        static function (mixed $message) use (&$serverChunks): void {
            $serverChunks[] = $message;
        },
    );

    $bidiChunks = [];
    $bidi = $client->bidiStream(
        method: 'Orders/Chat',
        messages: [['id' => 1], ['id' => 2]],
        onMessage: static function (mixed $message) use (&$bidiChunks): void {
            $bidiChunks[] = $message;
        },
    );

    expect($server->successful)->toBeTrue();
    expect($serverChunks)->toHaveCount(2);
    expect($server->response?->trailers->first('x-trailer'))->toBe('list-end');
    expect($bidi->successful)->toBeTrue();
    expect($bidiChunks)->toHaveCount(2);
});

it('preserves non-ok getStatus results for generated streams', function (): void {
    $stub = new class {
        public function List(mixed $message, array $metadata = [], array $options = []): object
        {
            unset($message, $metadata, $options);

            return new class {
                public function getStatus(): object
                {
                    return (object) ['code' => 13, 'details' => 'upstream failure'];
                }

                public function responses(): iterable
                {
                    yield ['row' => 1];
                }
            };
        }
    };

    $result = GrpcClient::usingGeneratedStub($stub)->serverStream(
        new GrpcRequest('Orders/List', []),
        static function (mixed $message): void {
            unset($message);
        },
    );

    expect($result->successful)->toBeFalse();
    expect($result->statusCode)->toBe(13);
});


it('documents that generated bidi flow is write-then-read rather than interactive full duplex', function (): void {
    $cancelled = false;
    $stub = new class($cancelled) {
        public function __construct(private bool &$cancelled) {}

        public function Chat(array $metadata = [], array $options = []): object
        {
            unset($metadata, $options);

            return new class($this->cancelled) {
                private int $writes = 0;

                private bool $readStarted = false;

                public function __construct(private bool &$cancelled) {}

                public function cancel(): void
                {
                    $this->cancelled = true;
                }

                public function closeWrite(): void {}

                public function getStatus(): object
                {
                    return (object) ['code' => 0];
                }

                public function read(): ?array
                {
                    $this->readStarted = true;

                    return null;
                }

                public function write(mixed $message): void
                {
                    unset($message);
                    $this->writes++;

                    if ($this->writes > 1 && !$this->readStarted) {
                        throw new RuntimeException('interactive peer requires an inbound read before the next write');
                    }
                }
            };
        }
    };

    $result = GrpcClient::usingGeneratedStub($stub)->bidiStream(
        method: 'Orders/Chat',
        messages: [['id' => 1], ['id' => 2]],
        onMessage: static function (mixed $message): void {
            unset($message);
        },
    );

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('interactive peer requires an inbound read');
    expect($cancelled)->toBeTrue();
});
