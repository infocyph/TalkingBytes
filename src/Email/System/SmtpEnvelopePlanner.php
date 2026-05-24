<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\SmtpUtf8Policy;
use Infocyph\TalkingBytes\Email\ValueObject\EmailHeaders;
use RuntimeException;

final readonly class SmtpEnvelopePlanner
{
    public function __construct(
        private SmtpConfig $config,
    ) {}

    public function assertSmtpUtf8Policy(EmailMessage $message, SmtpCapabilities $capabilities): bool
    {
        $requiresSmtpUtf8 = $this->messageRequiresSmtpUtf8($message);

        if ($this->config->utf8Policy === SmtpUtf8Policy::Reject && $requiresSmtpUtf8) {
            throw new RuntimeException('SMTPUTF8 addresses are not allowed by current policy.');
        }

        if ($this->config->utf8Policy === SmtpUtf8Policy::Require && !$capabilities->has('SMTPUTF8')) {
            throw new RuntimeException('SMTPUTF8 is required by configuration but not supported by server.');
        }

        if ($requiresSmtpUtf8 && !$capabilities->has('SMTPUTF8')) {
            throw new RuntimeException('Message requires SMTPUTF8, but server does not advertise SMTPUTF8.');
        }

        return $requiresSmtpUtf8;
    }

    public function buildMailFromCommand(
        string $senderEmail,
        EmailHeaders $headers,
        SmtpCapabilities $capabilities,
        int $messageSizeBytes,
        ?int $sizeLimit,
        bool $messageContainsNonAscii,
        bool $requiresSmtpUtf8,
    ): string {
        $mailFromArgs = [];

        if ($capabilities->has('DSN')) {
            $mailFromArgs[] = 'RET=' . ($headers->dsnReturnFull ? 'FULL' : 'HDRS');
            $mailFromArgs[] = 'ENVID=' . ($headers->dsnEnvelopeId ?? bin2hex(random_bytes(8)));
        }

        if ($sizeLimit !== null) {
            $mailFromArgs[] = 'SIZE=' . $messageSizeBytes;
        }

        if ($requiresSmtpUtf8 && $capabilities->has('SMTPUTF8')) {
            $mailFromArgs[] = 'SMTPUTF8';
        }

        if ($this->shouldUseEightBitMime($messageContainsNonAscii, $capabilities)) {
            $mailFromArgs[] = 'BODY=8BITMIME';
        }

        $mailFrom = sprintf('MAIL FROM:<%s>', $senderEmail);
        if ($mailFromArgs !== []) {
            $mailFrom .= ' ' . implode(' ', $mailFromArgs);
        }

        return $mailFrom;
    }

    public function buildRcptCommand(string $recipientEmail, EmailHeaders $headers, SmtpCapabilities $capabilities): string
    {
        $rcptCommand = sprintf('RCPT TO:<%s>', $recipientEmail);

        if ($capabilities->has('DSN')) {
            $notify = $this->dsnNotifyDirective($headers);
            if ($notify !== null) {
                $rcptCommand .= ' NOTIFY=' . $notify;
            }
        }

        return $rcptCommand;
    }

    private function containsNonAscii(string $value): bool
    {
        return preg_match('/[^\x00-\x7F]/', $value) === 1;
    }

    private function dsnNotifyDirective(EmailHeaders $headers): ?string
    {
        $notifyFlags = [];

        if ($headers->dsnNotifySuccess) {
            $notifyFlags[] = 'SUCCESS';
        }

        if ($headers->dsnNotifyFailure) {
            $notifyFlags[] = 'FAILURE';
        }

        if ($headers->dsnNotifyDelay) {
            $notifyFlags[] = 'DELAY';
        }

        if ($notifyFlags === []) {
            return null;
        }

        return implode(',', $notifyFlags);
    }

    private function messageRequiresSmtpUtf8(EmailMessage $message): bool
    {
        $from = $message->envelope()->from;
        if ($from !== null && $this->containsNonAscii($from->email)) {
            return true;
        }

        return array_any($message->envelope()->recipients(), fn($recipient) => $this->containsNonAscii($recipient->email));
    }

    private function shouldUseEightBitMime(bool $messageContainsNonAscii, SmtpCapabilities $capabilities): bool
    {
        if (!$this->config->allowEightBitMime || !$capabilities->has('8BITMIME')) {
            return false;
        }

        return $messageContainsNonAscii;
    }
}
