<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Core\Support\StreamWaiter;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;
use Infocyph\TalkingBytes\Email\Exception\MailboxProtocolException;

/** Accumulates bounded IMAP wire responses independently of connection ownership. */
final class ImapResponseStreamReader
{
    /**
     * @param resource $socket
     * @param callable():string $readLine
     */
    public static function idleLine(
        mixed $socket,
        ?StreamWaiter $streamWaiter,
        Sleeper $sleeper,
        callable $readLine,
    ): ?string {
        $read = [$socket];
        $write = [];
        $except = [];
        $microseconds = $streamWaiter === null ? 250000 : 0;
        $ready = stream_select($read, $write, $except, 0, $microseconds);
        if ($ready === false) {
            throw new MailboxConnectionException('IMAP IDLE stream_select failed.');
        }

        if ($ready < 1) {
            if ($streamWaiter !== null) {
                $sleeper->milliseconds(10);
            }

            return null;
        }

        return rtrim($readLine(), "\r\n");
    }

    /**
     * @param callable():string $readLine
     * @param callable(int):string $readLiteral
     * @param callable(string):?int $literalSize
     */
    public static function read(
        string $tag,
        callable $readLine,
        callable $readLiteral,
        callable $literalSize,
        int $maxLines,
        int $maxBytes,
    ): ImapResponse {
        $lines = [];
        $literals = [];
        $status = 'NO';
        $totalBytes = 0;

        while (true) {
            $line = $readLine();
            $trimmed = rtrim($line, "\r\n");
            $lines[] = $trimmed;
            $totalBytes += strlen($line);

            if (count($lines) > $maxLines || $totalBytes > $maxBytes) {
                throw new MailboxProtocolException('IMAP response exceeds configured bounds.');
            }

            $size = $literalSize($trimmed);
            if ($size !== null) {
                $literal = $readLiteral($size);
                $totalBytes += strlen($literal);
                if ($totalBytes > $maxBytes) {
                    throw new MailboxProtocolException('IMAP response exceeds configured byte limit.');
                }

                $literals[] = $literal;
            }

            if (preg_match('/^' . preg_quote($tag, '/') . '\\s+(OK|NO|BAD)\\b/i', $trimmed, $matches) === 1) {
                $status = strtoupper($matches[1]);

                break;
            }
        }

        return new ImapResponse($tag, $status, $lines, $literals);
    }
}
