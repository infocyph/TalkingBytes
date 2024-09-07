<?php

namespace Infocyph\TakingBytes\Email;

use Infocyph\TakingBytes\Email\System\EmailBuilder;
use Infocyph\TakingBytes\Email\System\GenericSender;
use Infocyph\TakingBytes\Email\System\SMTPSender;

class Emailer
{
    private $to = [];
    private $cc = [];
    private $bcc = [];
    private $from = [];
    private $replyTo;
    private $subject;
    private $plainText;
    private $htmlContent;
    private $attachments = [];
    private $smtpConfigured = false;
    private $smtpConfig = [];

    public function __construct($fromEmail, $fromName)
    {
        $this->from = [
            'email' => $this->encodeNonAscii($fromEmail),
            'name' => $this->encodeNonAscii($fromName)
        ];
        $this->replyTo = $this->from['email']; // Default Reply-To
    }

    public function setSMTP(array $smtpConfig)
    {
        $this->smtpConfig = array_merge([
            'host' => '',
            'auth' => false,
            'username' => '',
            'password' => '',
            'port' => 25,
            'secure' => null
        ], $smtpConfig);
        $this->smtpConfigured = true;
        return $this;
    }

    public function to($email)
    {
        $this->to[] = $this->encodeNonAscii($email);
        return $this;
    }

    public function cc($email)
    {
        $this->cc[] = $this->encodeNonAscii($email);
        return $this;
    }

    public function bcc($email)
    {
        $this->bcc[] = $this->encodeNonAscii($email);
        return $this;
    }

    public function subject($subject)
    {
        $this->subject = $this->encodeMimeHeader($subject);
        return $this;
    }

    public function plainText($text)
    {
        $this->plainText = $text;
        return $this;
    }

    public function htmlContent($html)
    {
        $this->htmlContent = $html;
        return $this;
    }

    public function replyTo($email)
    {
        $this->replyTo = $this->encodeNonAscii($email);
        return $this;
    }

    public function attachment($filePath, $filename = null)
    {
        if (file_exists($filePath)) {
            $this->attachments[] = ['path' => $filePath, 'name' => $filename ?: basename($filePath)];
        }
        return $this;
    }

    public function send()
    {
        // Instantiate the builder
        $builder = new EmailBuilder();

        // Build headers using the user-provided input
        $headers = $builder->buildHeaders(
            $this->from,
            $this->cc,
            $this->bcc,
            $this->replyTo,
            $this->attachments
        );

        // Build the email body (plaintext will be auto-generated if not provided)
        $message = $builder->buildBody(
            $this->plainText,
            $this->htmlContent,
            $this->attachments
        );

        // Choose the appropriate sender method (SMTP or generic)
        if ($this->smtpConfigured) {
            return (new SMTPSender($this->from, $this->smtpConfig))->send($this->to, $message, $headers);
        }
        return (new GenericSender())->send($this->to, $this->subject, $message, $headers);
    }


    private function encodeNonAscii($string)
    {
        return preg_replace_callback('/[^\x20-\x7E]/', function ($matches) {
            return '=?UTF-8?B?' . base64_encode($matches[0]) . '?=';
        }, $string);
    }

    private function encodeMimeHeader($text)
    {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
}
