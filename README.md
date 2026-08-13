# TalkingBytes

[![Security & Standards](https://github.com/infocyph/TalkingBytes/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/TalkingBytes/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/TalkingBytes?color=green\&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2FTalkingBytes)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/TalkingBytes)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/TalkingBytes/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/TalkingBytes)
[![Documentation](https://img.shields.io/badge/Documentation-TalkingBytes-blue?logo=readthedocs&logoColor=white)](https://docs.infocyph.com/projects/TalkingBytes)

Protocol-focused communication toolkit for PHP.

TalkingBytes provides typed HTTP and gRPC pipelines, shared result/event primitives,
and protocol modules for:

- Email (SMTP/sendmail/mail/spool + IMAP/POP3/parser)
- HTTP (cURL + cURL multi)
- Webhook (sign/verify/replay)
- gRPC (adapter + retry + fake caller)

## Install

```bash
composer require infocyph/talkingbytes
```

Requirements:

- PHP `^8.4`
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
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;

$client = GrpcClient::using(
    static fn (GrpcRequest $request): GrpcResponse =>
        new GrpcResponse(GrpcStatus::Ok, ['ok' => true, 'echo' => $request->message]),
);

$result = $client->send(new GrpcRequest('/orders.v1.OrderService/Create', [
    'order_id' => 1001,
]));

$server = GrpcInboundDispatcher::new()->withHandler(
    '/orders.v1.OrderService/Create',
    static fn (GrpcInboundRequest $request): GrpcInboundResponse =>
        GrpcInboundResponse::ok(['received' => $request->message]),
);
```


## Security

Do not disclose suspected vulnerabilities in a public issue, discussion or pull request. Follow [SECURITY.md](SECURITY.md) and use [GitHub private vulnerability reporting](https://github.com/infocyph/TalkingBytes/security/advisories/new).

TalkingBytes is protected by [PHPForge](https://github.com/infocyph/PHPForge), which provides automated tests, static and taint analysis, dependency auditing, architecture checks and release-readiness gates. Automated controls do not replace responsible disclosure or manual review.

---

<div align="center">
  <sub><strong>Made with ❤️ for the PHP community</strong></sub><br />
  <sub><a href="LICENSE">MIT Licensed</a></sub><br />
  <a href="https://docs.infocyph.com/projects/TalkingBytes/">Documentation</a> •
  <a href="SECURITY.md">Security</a> •
  <a href="CODE_OF_CONDUCT.md">Code of Conduct</a> •
  <a href="CONTRIBUTING.md">Contributing</a><br />
  <span title="Issue templates" aria-label="Issue templates">🗂️</span>
  <a href="https://github.com/infocyph/TalkingBytes/issues/new?template=bug_report.yml">Bug</a> •
  <a href="https://github.com/infocyph/TalkingBytes/issues/new?template=feature_request.yml">Feature</a> •
  <a href="https://github.com/infocyph/TalkingBytes/issues/new?template=docs_improvement.yml">Documentation</a> •
  <a href="https://github.com/infocyph/TalkingBytes/issues/new?template=question.yml">Question</a> •
  <a href="https://github.com/infocyph/TalkingBytes/issues/new?template=ci_failure.yml">CI failure</a><br />
  <span title="Pull request templates" aria-label="Pull request templates">🔀</span>
  <a href="https://github.com/infocyph/TalkingBytes/compare/main...HEAD?quick_pull=1&amp;template=PULL_REQUEST_TEMPLATE.md">General</a> •
  <a href="https://github.com/infocyph/TalkingBytes/compare/main...HEAD?quick_pull=1&amp;template=bug_fix.md">Bug fix</a> •
  <a href="https://github.com/infocyph/TalkingBytes/compare/main...HEAD?quick_pull=1&amp;template=feature.md">Feature</a> •
  <a href="https://github.com/infocyph/TalkingBytes/compare/main...HEAD?quick_pull=1&amp;template=refactor.md">Refactor</a> •
  <a href="https://github.com/infocyph/TalkingBytes/compare/main...HEAD?quick_pull=1&amp;template=performance.md">Performance</a> •
  <a href="https://github.com/infocyph/TalkingBytes/compare/main...HEAD?quick_pull=1&amp;template=security_reliability.md">Security &amp; reliability</a> •
  <a href="https://github.com/infocyph/TalkingBytes/compare/main...HEAD?quick_pull=1&amp;template=documentation.md">Documentation</a> •
  <a href="https://github.com/infocyph/TalkingBytes/compare/main...HEAD?quick_pull=1&amp;template=maintenance.md">Maintenance</a>
</div>
