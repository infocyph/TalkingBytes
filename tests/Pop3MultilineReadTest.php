<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Enum\Pop3Security;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3SocketTransport;

function setPop3TransportConnection(Pop3SocketTransport $transport, string $buffer): mixed
{
    $stream = fopen('php://temp', 'r+');
    if (! is_resource($stream)) {
        throw new RuntimeException('Unable to allocate temp stream.');
    }

    fwrite($stream, $buffer);
    rewind($stream);
    stream_set_timeout($stream, 1);

    $property = new ReflectionProperty($transport, 'connection');
    $property->setValue($transport, $stream);

    return $stream;
}

/**
 * @param  resource  $stream
 */
function teardownPop3TransportConnection(Pop3SocketTransport $transport, mixed $stream): void
{
    if (is_resource($stream)) {
        fclose($stream);
    }

    $property = new ReflectionProperty($transport, 'connection');
    $property->setValue($transport, null);
}

/**
 * @return list<string>
 */
function invokeReadPop3Multiline(Pop3SocketTransport $transport): array
{
    $method = new ReflectionMethod($transport, 'readMultilineResponse');

    /** @var list<string> */
    return $method->invoke($transport);
}

it('parses POP3 multiline response with dot terminator and unescapes dot-stuffed lines', function (): void {
    $transport = new Pop3SocketTransport(new Pop3Config(
        host: 'pop3.example.com',
        port: 110,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
    ));

    $stream = setPop3TransportConnection($transport, implode("\r\n", [
        'first line',
        '..second line',
        '...single-dot-line',
        '.',
        '',
    ]));

    $lines = invokeReadPop3Multiline($transport);
    teardownPop3TransportConnection($transport, $stream);

    expect($lines)->toBe([
        'first line',
        '.second line',
        '..single-dot-line',
    ]);
});

it('supports POP3 empty multiline body', function (): void {
    $transport = new Pop3SocketTransport(new Pop3Config(
        host: 'pop3.example.com',
        port: 110,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
    ));

    $stream = setPop3TransportConnection($transport, ".\r\n");

    $lines = invokeReadPop3Multiline($transport);
    teardownPop3TransportConnection($transport, $stream);

    expect($lines)->toBe([]);
});

it('fails POP3 multiline read when terminator is missing', function (): void {
    $transport = new Pop3SocketTransport(new Pop3Config(
        host: 'pop3.example.com',
        port: 110,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
    ));

    $stream = setPop3TransportConnection($transport, "line-without-terminator\r\n");

    expect(fn () => invokeReadPop3Multiline($transport))
        ->toThrow(MailboxConnectionException::class);

    teardownPop3TransportConnection($transport, $stream);
});

it('fails POP3 multiline read when server closes stream mid-response', function (): void {
    $transport = new Pop3SocketTransport(new Pop3Config(
        host: 'pop3.example.com',
        port: 110,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
    ));

    $stream = setPop3TransportConnection($transport, '');

    expect(fn () => invokeReadPop3Multiline($transport))
        ->toThrow(MailboxConnectionException::class);

    teardownPop3TransportConnection($transport, $stream);
});
