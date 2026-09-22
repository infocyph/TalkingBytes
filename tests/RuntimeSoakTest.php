<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Webhook\Replay\InMemoryWebhookReplayStore;

it('does not retain repeated protocol object graphs globally', function (): void {
    $references = [];

    for ($iteration = 0; $iteration < 250; $iteration++) {
        $http = HttpClient::using(new FakeHttpTransport());
        $email = Emailer::fake();
        $replay = new InMemoryWebhookReplayStore(32);
        $grpc = GrpcClient::using(
            static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(
                GrpcStatus::Ok,
                $request->message,
            ),
        );

        if ($iteration % 25 === 0) {
            $references[] = WeakReference::create($http);
            $references[] = WeakReference::create($email);
            $references[] = WeakReference::create($replay);
            $references[] = WeakReference::create($grpc);
        }

        unset($http, $email, $replay, $grpc);
    }

    gc_collect_cycles();

    foreach ($references as $reference) {
        expect($reference->get())->toBeNull();
    }
});

it('starts mutable test and replay state clean on every new graph', function (): void {
    for ($iteration = 0; $iteration < 250; $iteration++) {
        $transport = new FakeHttpTransport();
        $client = HttpClient::using($transport);
        $replay = new InMemoryWebhookReplayStore(2);

        expect($transport->sentRequests())->toBe([])
            ->and($replay->claim('soak', 'delivery', 60))->toBeTrue();

        unset($client, $transport, $replay);
    }
});
