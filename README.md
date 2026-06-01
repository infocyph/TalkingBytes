# TalkingBytes

Transport-agnostic communication toolkit for PHP.

TalkingBytes provides a shared middleware/event core with protocol modules for:

- Email (SMTP/sendmail/mail/spool + IMAP/POP3/parser)
- HTTP (cURL + cURL multi)
- Webhook (sign/verify/replay)
- gRPC (adapter + retry + fake caller)

## Install

```bash
composer require infocyph/talkingbytes
```

Requirements:

- PHP `>=8.4`
- `ext-curl`
- `ext-fileinfo`
- `ext-openssl`

## Quick Start

### HTTP

```php
use Infocyph\TalkingBytes\Http\HttpClient;

$result = HttpClient::curl()
    ->withBearerToken($token)
    ->timeout(10)
    ->postJson('https://api.example.com/orders', [
        'order_id' => 1001,
        'amount' => 500,
    ]);

if ($result->successful) {
    $data = $result->response->json();
}
```

### Email

```php
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\EmailMessage;

$result = Email::sender()->usingNull()->send(
    EmailMessage::new()
        ->from('sender@example.com')
        ->to('user@example.com')
        ->subject('Hello')
        ->text('Hello from TalkingBytes')
);
```

### Webhook

```php
use Infocyph\TalkingBytes\Webhook\Webhook;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;

$delivery = Webhook::sender($httpClient)
    ->withSecret('whsec_test')
    ->send(
        WebhookMessage::event('order.created')
            ->url('https://merchant.example.com/webhook')
            ->payload(['order_id' => 1001])
    );
```

### gRPC

```php
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\GrpcServer;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;

$client = GrpcClient::using(
    static fn (GrpcRequest $request): GrpcResponse =>
        new GrpcResponse(GrpcStatus::Ok, ['ok' => true, 'echo' => $request->message]),
);

$result = $client->send(new GrpcRequest('/orders.v1.OrderService/Create', [
    'order_id' => 1001,
]));

$server = GrpcServer::new()->withHandler(
    '/orders.v1.OrderService/Create',
    static fn (GrpcInboundRequest $request): GrpcInboundResponse =>
        GrpcInboundResponse::ok(['received' => $request->message]),
);
```

## Full Documentation

Detailed docs are in `docs/` (Read the Docs structure):

- `docs/getting-started.rst`
- `docs/architecture.rst`
- `docs/email/index.rst`
- `docs/http/index.rst`
- `docs/webhook/index.rst`
- `docs/grpc/index.rst`
- `docs/grpc/quickstart.rst` (Node A -> Node B microservice example)
- `docs/grpc/inbound-outbound.rst` (inbound + outbound gRPC)
- `docs/webhook/end-to-end.rst` (full sender/verifier/receiver flow)
- `docs/events.rst`
- `docs/testing.rst`
- `docs/security.rst`
- `docs/performance.rst`
- `docs/extensions.rst`
- `docs/naming.rst`
- `docs/release-checklist.rst`

## Quality Gates

Run full quality and test pipeline:

```bash
composer ic:ci
```

## License

MIT
