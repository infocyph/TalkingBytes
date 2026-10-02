<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;
use Infocyph\TalkingBytes\Email\Mailbox\SocketMailboxRuntime;
use Infocyph\TalkingBytes\Email\System\SmtpIoRuntime;

it('bounds blocking SMTP and mailbox writes by their operation deadline', function (): void {
    $saturatedPair = static function (): array {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Unable to create socket pair for write deadline test.');
        }

        if (!stream_set_blocking($pair[0], false)) {
            fclose($pair[0]);
            fclose($pair[1]);

            throw new RuntimeException('Unable to configure non-blocking socket for write deadline test.');
        }

        $chunk = str_repeat('x', 65_536);
        set_error_handler(
            static fn(): bool => true,
            E_NOTICE | E_WARNING,
        );

        try {
            for ($attempt = 0; $attempt < 1_024; $attempt++) {
                $written = fwrite($pair[0], $chunk);
                if ($written === false || $written === 0) {
                    break;
                }
            }
        } finally {
            restore_error_handler();
        }

        if (!stream_set_blocking($pair[0], true)) {
            fclose($pair[0]);
            fclose($pair[1]);

            throw new RuntimeException('Unable to restore blocking socket for write deadline test.');
        }
        stream_set_timeout($pair[0], 0, 200_000);

        return $pair;
    };

    $smtpPair = $saturatedPair();
    try {
        $startedAt = hrtime(true);

        expect(fn() => (new SmtpIoRuntime())->write(
            $smtpPair[0],
            'x',
            OperationDeadline::after(0.03),
        ))->toThrow(RuntimeException::class, 'SMTP command deadline exceeded');

        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        expect($elapsedSeconds)->toBeLessThan(0.15);
    } finally {
        fclose($smtpPair[0]);
        fclose($smtpPair[1]);
    }

    $mailboxPair = $saturatedPair();
    try {
        $startedAt = hrtime(true);

        expect(fn() => SocketMailboxRuntime::write(
            $mailboxPair[0],
            'x',
            'imap',
            deadline: OperationDeadline::after(0.03),
        ))->toThrow(MailboxConnectionException::class, 'IMAP command deadline exceeded');

        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        expect($elapsedSeconds)->toBeLessThan(0.15);
    } finally {
        fclose($mailboxPair[0]);
        fclose($mailboxPair[1]);
    }
});
