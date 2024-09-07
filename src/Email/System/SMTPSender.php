<?php

namespace Infocyph\TakingBytes\Email\System;

class SMTPSender
{
    private array $status = ['sent' => false, 'error' => null];

    public function __construct(private array $from, private array $smtpConfig)
    {
    }

    public function send(array $to, string $message, string $headers)
    {
        return $this->sendViaSMTP($to, $message, $headers);
    }

    private function sendViaSMTP($to, $message, $headers)
    {
        $connection = $this->openConnection();
        if (!$connection) {
            return false;
        }

        if (!$this->initializeConnection($connection)) {
            return false;
        }

        if (!$this->authenticate($connection)) {
            return false;
        }

        if (!$this->sendEmail($connection, $to, $headers, $message)) {
            return false;
        }

        $this->closeConnection($connection);
        $this->status['sent'] = true;
        return $this->status;
    }

    private function openConnection()
    {
        $smtpHost = $this->smtpConfig['host'];
        $smtpPort = $this->smtpConfig['port'];
        $connection = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 30);

        if (!$connection) {
            $this->status['error'] = "Failed to connect to SMTP server: $errstr ($errno)";
            return false;
        }

        return $connection;
    }

    private function initializeConnection($connection)
    {
        $smtpSecure = $this->smtpConfig['secure'] ?? 'none';
        $smtpHost = $this->smtpConfig['host'];

        if (!$this->getServerResponse($connection, 'initializeConnection')) {
            return false;
        }

        switch ($smtpSecure) {
            case 'tls':
                fwrite($connection, "EHLO $smtpHost\r\n");
                if (!$this->getServerResponse($connection, 'EHLO (before STARTTLS)')) {
                    return false;
                }

                fwrite($connection, "STARTTLS\r\n");
                if (!$this->getServerResponse($connection, 'STARTTLS')) {
                    return false;
                }

                if (!stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $this->status['error'] = "TLS encryption failed.";
                    return false;
                }

                fwrite($connection, "EHLO $smtpHost\r\n");
                if (!$this->getServerResponse($connection, 'EHLO (after STARTTLS)')) {
                    return false;
                }
                break;

            case 'ssl':
                if (!stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_SSLv23_CLIENT)) {
                    $this->status['error'] = "SSL encryption failed.";
                    return false;
                }

                fwrite($connection, "EHLO $smtpHost\r\n");
                if (!$this->getServerResponse($connection, 'EHLO (after SSL)')) {
                    return false;
                }
                break;

            default:
                fwrite($connection, "EHLO $smtpHost\r\n");
                if (!$this->getServerResponse($connection, 'EHLO (no encryption)')) {
                    return false;
                }
                break;
        }

        return true;
    }

    private function authenticate($connection)
    {
        $smtpUser = $this->smtpConfig['username'];
        $smtpPass = $this->smtpConfig['password'];

        fwrite($connection, "AUTH LOGIN\r\n");
        if (!$this->getServerResponse($connection, 'AUTH LOGIN')) {
            return false;
        }

        fwrite($connection, base64_encode($smtpUser) . "\r\n");
        if (!$this->getServerResponse($connection, 'username')) {
            return false;
        }

        fwrite($connection, base64_encode($smtpPass) . "\r\n");
        if (!$this->getServerResponse($connection, 'password')) {
            return false;
        }

        return true;
    }

    private function sendEmail($connection, $to, $headers, $message)
    {
        // Send MAIL FROM with email
        fwrite($connection, "MAIL FROM: <{$this->from['email']}>\r\n");
        if (!$this->getServerResponse($connection, 'MAIL FROM')) {
            return false;
        }

        // Send RCPT TO for each recipient
        foreach ((array)$to as $recipient) {
            fwrite($connection, "RCPT TO: <$recipient>\r\n");
            if (!$this->getServerResponse($connection, 'RCPT TO')) {
                return false;
            }
        }

        // Initiate DATA command
        fwrite($connection, "DATA\r\n");
        if (!$this->getServerResponse($connection, 'DATA')) {
            return false;
        }

        // Send headers and message body
        fwrite($connection, "$headers\r\n$message\r\n.\r\n");
        if (!$this->getServerResponse($connection, 'message body')) {
            return false;
        }

        return true;
    }

    private function closeConnection($connection)
    {
        fwrite($connection, "QUIT\r\n");
        fclose($connection);
    }

    private function getServerResponse($connection, $stage = 'unknown')
    {
        $response = fgets($connection, 512);
        if (!str_starts_with($response, '250') && !str_starts_with($response, '354')) {
            $this->status['error'] = "SMTP Error at stage '$stage': $response";
            return false;
        }
        return true;
    }
}
