<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;

final class SocketMailboxRuntime
{
    /**
     * @return resource
     */
    public static function connect(
        string $host,
        int $port,
        int $timeoutSeconds,
        string $protocolLabel,
        bool $ssl,
    ): mixed {
        $targetHost = sprintf('%s://%s:%d', $ssl ? 'ssl' : 'tcp', $host, $port);
        $errno = 0;
        $errstr = '';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);
        $connection = stream_socket_client(
            $targetHost,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!is_resource($connection)) {
            throw new MailboxConnectionException(sprintf(
                'Unable to connect to %s server: %s (%d)',
                $protocolLabel,
                $errstr,
                $errno,
            ));
        }

        stream_set_timeout($connection, $timeoutSeconds);

        return $connection;
    }

    /**
     * @param array{host:string,port:int} $endpoint
     */
    public static function dispatchFinish(
        string $protocol,
        string $command,
        string $status,
        int $startedAtMs,
        array $endpoint,
    ): void {
        CommunicationEventBus::dispatch('mailbox.command.finish', [
            'protocol' => $protocol,
            'host' => $endpoint['host'],
            'port' => $endpoint['port'],
            'command' => $command,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) * 1000) - $startedAtMs),
        ]);
    }

    /**
     * @param array{host:string,port:int} $endpoint
     * @return array{command:string,duration_ms:int}
     */
    public static function dispatchStart(string $protocol, string $command, array $endpoint): array
    {
        $redactedCommand = MailboxCommandRedactor::redact($protocol, $command);
        CommunicationEventBus::dispatch('mailbox.command.start', [
            'protocol' => $protocol,
            'host' => $endpoint['host'],
            'port' => $endpoint['port'],
            'command' => $redactedCommand,
        ]);

        return [
            'command' => $redactedCommand,
            'duration_ms' => (int) round(microtime(true) * 1000),
        ];
    }

    /**
     * @param resource $connection
     */
    public static function enableTls(mixed $connection, string $protocol): void
    {
        set_error_handler(
            static fn(): bool => true,
            E_WARNING,
        );

        try {
            $enabled = stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        } finally {
            restore_error_handler();
        }

        if ($enabled !== true) {
            throw new MailboxConnectionException(sprintf('Unable to enable TLS on %s socket.', strtoupper($protocol)));
        }
    }

    /**
     * @param resource $connection
     * @param positive-int $maxLength
     */
    public static function readLine(mixed $connection, string $protocol, int $maxLength = 8192): string
    {
        $line = fgets($connection, max(1, $maxLength));
        if ($line === false) {
            /** @var array<string, mixed> $meta */
            $meta = stream_get_meta_data($connection);
            if (($meta['timed_out'] ?? false) === true) {
                throw new MailboxConnectionException(sprintf('%s server response timed out.', strtoupper($protocol)));
            }

            throw new MailboxConnectionException(sprintf('Failed to read from %s socket.', strtoupper($protocol)));
        }
        if (!str_ends_with($line, "\n") && !feof($connection)) {
            throw new MailboxConnectionException(sprintf(
                '%s response line exceeds %d bytes.',
                strtoupper($protocol),
                $maxLength - 1,
            ));
        }

        return $line;
    }

    public static function shouldStartTls(bool $required, bool $supported, string $protocol): bool
    {
        if ($supported) {
            return true;
        }

        if ($required) {
            throw new MailboxConnectionException(sprintf(
                '%s STARTTLS is required but not supported by server.',
                strtoupper($protocol),
            ));
        }

        return false;
    }

    /**
     * @param resource $connection
     */
    public static function write(mixed $connection, string $value, string $protocol): void
    {
        $remaining = $value;
        while ($remaining !== '') {
            set_error_handler(
                static fn(): bool => true,
                E_NOTICE | E_WARNING,
            );

            try {
                $written = fwrite($connection, $remaining);
            } finally {
                restore_error_handler();
            }

            if ($written === false || $written === 0) {
                throw new MailboxConnectionException(sprintf('Failed writing to %s socket.', strtoupper($protocol)));
            }

            $remaining = substr($remaining, $written);
        }
    }
}
