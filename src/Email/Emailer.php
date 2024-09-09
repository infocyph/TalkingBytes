<?php

namespace Infocyph\TakingBytes\Email;

use Infocyph\TakingBytes\Email\System\EmailBuilder;
use Infocyph\TakingBytes\Email\System\GenericSender;
use Infocyph\TakingBytes\Email\System\SMTPSender;

final class Emailer
{
    // Recipients grouped (to, cc, bcc)
    private array $recipients = [
        'to' => [],
        'cc' => [],
        'bcc' => []
    ];

    // Message details grouped
    private array $messageDetails = [
        'messageId' => '',
        'inReplyTo' => '',
        'references' => []
    ];

    // General headers grouped
    private array $generalHeaders = [
        'language' => '',
        'priority' => null,
        'mailer' => ''
    ];

    // List headers grouped
    private array $listHeaders = [
        'listId' => '',
        'unsubscribe' => '',
        'subscribe' => '',
        'archive' => ''
    ];

    // Miscellaneous headers grouped
    private array $miscHeaders = [
        'confirmedOptIn' => null,
        'spamStatus' => '',
        'organization' => '',
        'dispositionNotificationTo' => ''
    ];

    private string $subject;
    private string $htmlContent;
    private string $plainText;
    private string $replyTo;
    private array $attachments = [];
    private array $smtpConfig = [];

    /**
     * @var bool
     */
    private bool $smtpConfigured = false;

    public function __construct($fromEmail, $fromName)
    {
        $this->from = [
            'email' => $this->encodeNonAscii($fromEmail),
            'name' => $this->encodeNonAscii($fromName)
        ];
        $this->replyTo = $this->from['email']; // Default Reply-To
    }

    public function setSMTP(array $smtpConfig): self
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

    // Set recipients (to, cc, bcc)
    public function setRecipients(array $to, array $cc = [], array $bcc = []): self
    {
        $this->recipients['to'] = array_map([$this, 'encodeNonAscii'], $to);
        $this->recipients['cc'] = array_map([$this, 'encodeNonAscii'], $cc);
        $this->recipients['bcc'] = array_map([$this, 'encodeNonAscii'], $bcc);
        return $this;
    }

    // Set message details (subject, content)
    public function setMessage(string $subject, string $htmlContent, string $plainText = ''): self
    {
        $this->subject = $this->encodeMimeHeader($subject);
        $this->htmlContent = $htmlContent;
        $this->plainText = $plainText;
        return $this;
    }

    // Set reply-to email
    public function setReplyTo(string $email): self
    {
        $this->replyTo = $this->encodeNonAscii($email);
        return $this;
    }

    // Set message-specific headers
    public function setMessageDetails(string $messageId = '', string $inReplyTo = '', array $references = []): self
    {
        $this->messageDetails['messageId'] = $messageId;
        $this->messageDetails['inReplyTo'] = $inReplyTo;
        $this->messageDetails['references'] = $references;
        return $this;
    }

    // Set general headers
    public function setGeneralHeaders(string $language = '', int $priority = null, string $mailer = ''): self
    {
        $this->generalHeaders['language'] = $language;
        $this->generalHeaders['priority'] = $priority;
        $this->generalHeaders['mailer'] = $mailer;
        return $this;
    }

    // Set list headers
    public function setListHeaders(
        string $listId = '',
        string $unsubscribe = '',
        string $subscribe = '',
        string $archive = ''
    ): self {
        $this->listHeaders['listId'] = $listId;
        $this->listHeaders['unsubscribe'] = $unsubscribe;
        $this->listHeaders['subscribe'] = $subscribe;
        $this->listHeaders['archive'] = $archive;
        return $this;
    }

    // Set miscellaneous headers
    public function setMiscHeaders(
        bool $confirmedOptIn = null,
        string $spamStatus = '',
        string $organization = '',
        string $dispositionNotificationTo = ''
    ): self {
        $this->miscHeaders['confirmedOptIn'] = $confirmedOptIn;
        $this->miscHeaders['spamStatus'] = $spamStatus;
        $this->miscHeaders['organization'] = $organization;
        $this->miscHeaders['dispositionNotificationTo'] = $dispositionNotificationTo;
        return $this;
    }

    // Add attachment
    public function attachment($filePath, $filename = null): self
    {
        if (file_exists($filePath)) {
            $this->attachments[] = ['path' => $filePath, 'name' => $filename ?: basename($filePath)];
        }
        return $this;
    }

    // Send the email
    public function send()
    {
        $builder = new EmailBuilder($this->from);

        // Set common headers
        $builder->setCommonHeaders(
            $this->recipients['to'],
            $this->subject,
            $this->recipients['cc'],
            $this->recipients['bcc'],
            $this->replyTo
        )
            ->setIdHeaders(
                $this->messageDetails['messageId'],
                $this->messageDetails['inReplyTo'],
                $this->messageDetails['references']
            )
            ->setGeneralHeaders(
                $this->generalHeaders['language'],
                $this->generalHeaders['priority'],
                $this->generalHeaders['mailer']
            )
            ->setListHeaders(
                $this->listHeaders['listId'],
                $this->listHeaders['unsubscribe'],
                $this->listHeaders['subscribe'],
                $this->listHeaders['archive']
            )
            ->setMiscHeaders(
                $this->miscHeaders['confirmedOptIn'],
                $this->miscHeaders['spamStatus'],
                $this->miscHeaders['organization'],
                $this->miscHeaders['dispositionNotificationTo']
            );

        // Choose the appropriate sending method (SMTP or generic)
        if ($this->smtpConfigured) {
            return (new SMTPSender($this->from, $this->smtpConfig))->send(
                $this->recipients['to'],
                $builder->setBody($this->htmlContent, $this->plainText, $this->attachments),
                $builder->getHeaders()
            );
        }

        return (new GenericSender())->send(
            $this->recipients['to'],
            $this->subject,
            $builder->setBody($this->htmlContent, $this->plainText, $this->attachments),
            $builder->getHeaders()
        );
    }

    // Helper to encode non-ASCII characters
    private function encodeNonAscii($string)
    {
        return preg_replace_callback('/[^\x20-\x7E]/', function ($matches) {
            return '=?UTF-8?B?' . base64_encode($matches[0]) . '?=';
        }, $string);
    }

    // Helper to encode MIME headers
    private function encodeMimeHeader($text)
    {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
}
