<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Enum\ImapSecurity;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;
use Infocyph\TalkingBytes\Email\Exception\MailboxProtocolException;
use Infocyph\TalkingBytes\Email\Mailbox\ImapResponse;
use Infocyph\TalkingBytes\Email\Mailbox\ImapSocketTransport;

function setImapTransportConnection(ImapSocketTransport $transport, string $buffer): mixed
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
function teardownImapTransportConnection(ImapSocketTransport $transport, mixed $stream): void
{
    if (is_resource($stream)) {
        fclose($stream);
    }

    $property = new ReflectionProperty($transport, 'connection');
    $property->setValue($transport, null);
}

function invokeReadTaggedResponse(ImapSocketTransport $transport, string $tag): ImapResponse
{
    $method = new ReflectionMethod($transport, 'readTaggedResponse');

    /** @var ImapResponse */
    return $method->invoke($transport, $tag);
}

it('reads multiple imap literals from a single tagged response', function (): void {
    $headerLiteral = "Subject: A\r\n\r\n";
    $bodyLiteral = 'HELLO';

    $wire = implode('', [
        '* 1 FETCH (BODY[HEADER] {'.strlen($headerLiteral)."}\r\n",
        $headerLiteral,
        ' BODY[TEXT] {'.strlen($bodyLiteral)."}\r\n",
        $bodyLiteral,
        ")\r\n",
        "A0001 OK done\r\n",
    ]);

    $transport = new ImapSocketTransport(new ImapConfig(
        host: '127.0.0.1',
        port: 143,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
        timeoutSeconds: 1,
    ));

    $stream = setImapTransportConnection($transport, $wire);
    $response = invokeReadTaggedResponse($transport, 'A0001');
    teardownImapTransportConnection($transport, $stream);

    expect($response->status)->toBe('OK');
    expect($response->literals)->toHaveCount(2);
    expect($response->literals[0])->toBe($headerLiteral);
    expect($response->literals[1])->toBe($bodyLiteral);
});

it('supports empty imap literal bodies', function (): void {
    $wire = implode('', [
        "* 1 FETCH (BODY[] {0}\r\n",
        ")\r\n",
        "A0001 OK done\r\n",
    ]);

    $transport = new ImapSocketTransport(new ImapConfig(
        host: '127.0.0.1',
        port: 143,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
        timeoutSeconds: 1,
    ));

    $stream = setImapTransportConnection($transport, $wire);
    $response = invokeReadTaggedResponse($transport, 'A0001');
    teardownImapTransportConnection($transport, $stream);

    expect($response->literals)->toHaveCount(1);
    expect($response->literals[0])->toBe('');
});

it('fails when imap server closes during literal read', function (): void {
    $wire = implode('', [
        "* 1 FETCH (RFC822 {10}\r\n",
        'short',
    ]);

    $transport = new ImapSocketTransport(new ImapConfig(
        host: '127.0.0.1',
        port: 143,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
        timeoutSeconds: 1,
    ));

    $stream = setImapTransportConnection($transport, $wire);

    expect(fn () => invokeReadTaggedResponse($transport, 'A0001'))
        ->toThrow(MailboxProtocolException::class, 'expected 10 bytes, received 5 bytes');

    teardownImapTransportConnection($transport, $stream);
});

it('reads literals across multiple FETCH lines in one tagged response', function (): void {
    $firstLiteral = "Subject: A\r\n\r\n";
    $secondLiteral = 'Body-A';
    $thirdLiteral = 'Body-B';

    $wire = implode('', [
        '* 1 FETCH (BODY.PEEK[HEADER] {'.strlen($firstLiteral)."}\r\n",
        $firstLiteral,
        ' BODY.PEEK[TEXT] {'.strlen($secondLiteral)."}\r\n",
        $secondLiteral,
        "\r\n)\r\n",
        '* 2 FETCH (BODY.PEEK[1.2] {'.strlen($thirdLiteral)."}\r\n",
        $thirdLiteral,
        "\r\n) trailing\r\n",
        "A0001 OK done\r\n",
    ]);

    $transport = new ImapSocketTransport(new ImapConfig(
        host: '127.0.0.1',
        port: 143,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
        timeoutSeconds: 1,
    ));

    $stream = setImapTransportConnection($transport, $wire);
    $response = invokeReadTaggedResponse($transport, 'A0001');
    teardownImapTransportConnection($transport, $stream);

    expect($response->status)->toBe('OK');
    expect($response->literals)->toBe([$firstLiteral, $secondLiteral, $thirdLiteral]);
});

it('fails with timeout-specific error while reading literal bytes', function (): void {
    $transport = new ImapSocketTransport(new ImapConfig(
        host: '127.0.0.1',
        port: 143,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
        timeoutSeconds: 1,
    ));

    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if (! is_array($pair) || ! is_resource($pair[0]) || ! is_resource($pair[1])) {
        throw new RuntimeException('Unable to allocate socket pair for timeout test.');
    }
    [$stream, $peer] = $pair;

    $property = new ReflectionProperty($transport, 'connection');
    $property->setValue($transport, $stream);
    stream_set_timeout($stream, 0, 50_000);

    $readExact = new ReflectionMethod($transport, 'readExact');

    expect(fn () => $readExact->invoke($transport, 3))
        ->toThrow(MailboxConnectionException::class);

    fclose($peer);
    fclose($stream);
    $property->setValue($transport, null);
});
