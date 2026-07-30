# TalkingBytes

[![Security & Standards](https://github.com/infocyph/TalkingBytes/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/TalkingBytes/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/TalkingBytes?color=green\&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2FTalkingBytes)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/TalkingBytes)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/TalkingBytes/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/TalkingBytes)
[![Documentation](https://img.shields.io/badge/Documentation-TalkingBytes-blue?logo=readthedocs&logoColor=white)](https://github.com/infocyph/TalkingBytes/tree/main/docs)

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

## Security

Protected by [PHPForge](https://github.com/infocyph/PHPForge) — an automated quality and security gate for PHP projects.

## Documentation

The complete guides cover
[email](https://github.com/infocyph/TalkingBytes/tree/main/docs/email),
[HTTP](https://github.com/infocyph/TalkingBytes/tree/main/docs/http),
[webhooks](https://github.com/infocyph/TalkingBytes/tree/main/docs/webhook),
[gRPC](https://github.com/infocyph/TalkingBytes/tree/main/docs/grpc),
[middleware and resilience](https://github.com/infocyph/TalkingBytes/blob/main/docs/middleware-and-resilience.rst),
[security](https://github.com/infocyph/TalkingBytes/blob/main/docs/security.rst), and
[testing](https://github.com/infocyph/TalkingBytes/blob/main/docs/testing.rst).

---

<div align="center">
  <sub><strong>Made with ❤️ for the PHP community</strong></sub><br />
  <sub><a href="LICENSE">MIT Licensed</a></sub><br />
  <a href="https://github.com/infocyph/TalkingBytes/tree/main/docs">Documentation</a> •
  <a href="SECURITY.md">Security</a> •
  <a href="CODE_OF_CONDUCT.md">Code of Conduct</a> •
  <a href="CONTRIBUTING.md">Contributing</a> •
  <a href="https://github.com/infocyph/TalkingBytes/issues">Report Bug</a> •
  <a href="https://github.com/infocyph/TalkingBytes/issues">Request Feature</a>
</div>
