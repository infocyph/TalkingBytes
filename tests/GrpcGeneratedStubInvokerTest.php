<?php

declare(strict_types=1);

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
