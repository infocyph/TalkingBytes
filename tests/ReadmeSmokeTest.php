<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\MailboxSearch;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;
use Infocyph\TalkingBytes\Email\Parser\AuthenticationResultsParser;
use Infocyph\TalkingBytes\Email\Parser\BounceParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;
use Infocyph\TalkingBytes\Grpc\Testing\FakeGrpcCaller;
use Infocyph\TalkingBytes\Http\Concurrent\RequestPool;
use Infocyph\TalkingBytes\Http\Cookie\CookieJar;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;
use Infocyph\TalkingBytes\Webhook\WebhookSender;

it('contains core usage examples in README', function (): void {
    $readme = file_get_contents(__DIR__ . '/../README.md');

    expect($readme)->toBeString();
    expect($readme)->toContain('# TalkingBytes');
    expect($readme)->toContain('## Quick Start');
    expect($readme)->toContain('### HTTP');
    expect($readme)->toContain('### Email');
    expect($readme)->toContain('### Webhook');
    expect($readme)->toContain('### gRPC');
    expect($readme)->toContain('## Full Documentation');
    expect($readme)->toContain('docs/http/index.rst');
    expect($readme)->toContain('docs/email/index.rst');
    expect($readme)->toContain('docs/webhook/index.rst');
    expect($readme)->toContain('docs/grpc/index.rst');
    expect($readme)->toContain('docs/security.rst');
    expect($readme)->toContain('docs/release-checklist.rst');
});

it('keeps release-level README examples syntactically valid in fake-safe mode', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('user@example.com')
        ->subject('README smoke')
        ->text('body');

    $smtp = Emailer::usingSmtp(new SmtpConfig('smtp.example.com'));
    $mail = Emailer::usingMailFunction();
    $null = Emailer::usingNull();
    $imap = Mailbox::usingImap(new ImapConfig('imap.example.com', username: 'u', password: 'p'));
    $pop3 = Pop3Mailbox::usingConfig(new Pop3Config('pop.example.com', username: 'u', password: 'p'));
    $search = MailboxSearch::new()->unseen()->limit(10);
    $auth = (new AuthenticationResultsParser())->parse('mx.example.com; dkim=pass header.d=example.com');
    $parsed = (new RawEmailParser())->parse("From: a@example.com\r\nTo: b@example.com\r\nSubject: S\r\n\r\nBody");
    $bounce = (new BounceParser())->parse($parsed);
    $http = HttpClient::fake()->withCookieJar(new CookieJar())->withHttpRetry(new HttpRetryPolicy(baseDelayMs: 0));
    $pool = HttpClient::multi(2);
    $webhookSender = WebhookSender::usingHttpWithRetryProfile(HttpClient::fake(), attempts: 2, baseDelayMs: 0);
    $httpRequest = HttpRequest::get('https://api.example.com/users')->query('page', 1);
    $grpcFake = (new FakeGrpcCaller())->pushOk(['ok' => true]);
    $grpcClient = GrpcClient::using(
        static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(GrpcStatus::Ok, $request->message),
    )->withGrpcRetry(GrpcRetryPolicy::standard(attempts: 2, baseDelayMs: 0));
    $grpcResult = $grpcClient->send(new GrpcRequest('Orders/Create', ['order_id' => 1001]));
    $grpcFakeClient = GrpcClient::using($grpcFake);
    $grpcFakeClient->send(new GrpcRequest('Orders/Create', ['order_id' => 1002]));

    expect($message->headersData()->subject)->toBe('README smoke');
    expect($smtp)->toBeInstanceOf(Emailer::class);
    expect($mail)->toBeInstanceOf(Emailer::class);
    expect($null)->toBeInstanceOf(Emailer::class);
    expect($imap)->toBeInstanceOf(Mailbox::class);
    expect($pop3)->toBeInstanceOf(Pop3Mailbox::class);
    expect($search->limit)->toBe(10);
    expect($auth->passedDkim())->toBeTrue();
    expect($bounce)->toBeNull();
    expect($http)->toBeInstanceOf(HttpClient::class);
    expect($pool->maxConcurrency(2))->toBeInstanceOf(RequestPool::class);
    expect($webhookSender)->toBeInstanceOf(WebhookSender::class);
    expect($httpRequest->buildUrl())->toContain('page=1');
    expect($grpcResult->successful)->toBeTrue();
    $grpcFake->assert()->assertCallCount(1);
    expect(Email::mailbox()->usingPop3(new Pop3Config('pop.example.com', username: 'u', password: 'p')))
        ->toBeInstanceOf(Pop3Mailbox::class);
});
