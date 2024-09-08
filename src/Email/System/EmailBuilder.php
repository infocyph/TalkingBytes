<?php

namespace Infocyph\TakingBytes\Email\System;

class EmailBuilder
{
    private string $boundaryAlternative;
    private ?string $boundaryMixed = null;

    private array $headers = [];

    public function __construct(private array $from)
    {
        $this->boundaryAlternative = sha1(uniqid(time(), true));
    }

    public function setCommonHeaders(array $to, string $subject, array $cc = [], $bcc = [], $replyTo = '')
    {
        $this->headers = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode($this->from['name']) . '?= <' . $this->from['email'] . '>',
            'To: ' . implode(',', $to),
            'Reply-To: ' . $replyTo,
            'Subject: ' . $subject
        ];
        if (!empty($cc)) {
            $this->headers[] = "Cc: " . implode(',', $cc);
        }
        if (!empty($bcc)) {
            $this->headers[] = "Bcc: " . implode(',', $bcc);
        }
        return $this;
    }

    public function setIdHeaders(string $messageId, string $inReplyTo = '', array $references = [])
    {
        $domain = substr(strrchr($this->from['email'], "@"), 1);

        $this->headers[] = "Message-ID: <$messageId>";

        if (!empty($inReplyTo)) {
            $this->headers[] = "In-Reply-To: <$inReplyTo@$domain>";
        }
        if (!empty($references)) {
            $this->headers[] = 'References: ' . implode(
                    ' ',
                    array_map(function ($ref) use ($domain) {
                        return "<$ref@$domain>";
                    }, $references)
                );
        }
        return $this;
    }

    public function setBody($htmlContent, $plainText = '', $attachments = [])
    {
        if (empty($plainText) && !empty($htmlContent)) {
            $plainText = strip_tags($htmlContent);
        }
        $this->headers[] = 'MIME-Version: 1.0';
        $message = $this->buildAlternativeBody($plainText, $htmlContent);
        if (!empty($attachments)) {
            $this->boundaryMixed = sha1(uniqid(time(), true));
            $this->headers[] = "Content-Type: multipart/mixed; boundary=\"$this->boundaryMixed\"";
            return $this->wrapWithMixedBoundary($message, $attachments);
        }
        $this->headers[] = "Content-Type: multipart/alternative; boundary=\"$this->boundaryAlternative\"";
        return $message;
    }

    public function getHeaders()
    {
        return implode("\r\n", $this->headers);
    }

    private function buildAlternativeBody($plainText, $htmlContent)
    {
        $message = $this->buildPlainTextPart($plainText);
        if (!empty($htmlContent)) {
            $message .= $this->buildHtmlPart($htmlContent);
        }
        $message .= "--$this->boundaryAlternative--\r\n";
        return $message;
    }

    private function wrapWithMixedBoundary($message, $attachments)
    {
        return "--$this->boundaryMixed\r\n"
            . "Content-Type: multipart/alternative; boundary=\"$this->boundaryAlternative\"\r\n\r\n"
            . $message
            . $this->buildAttachmentsPart($attachments)
            . "--$this->boundaryMixed--\r\n";
    }

    private function buildPlainTextPart($plainText)
    {
        return "--$this->boundaryAlternative\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 7bit\r\n\r\n"
            . "$plainText\r\n\r\n";
    }

    private function buildHtmlPart($htmlContent)
    {
        return "--$this->boundaryAlternative\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($htmlContent) . "\r\n\r\n";
    }

    private function buildAttachmentsPart($attachments)
    {
        $attachmentsPart = "";
        foreach ($attachments as $attachment) {
            $filePath = $attachment['path'];
            $fileName = rawurlencode($attachment['name']);
            $fileType = mime_content_type($filePath);
            $fileContent = chunk_split(base64_encode(file_get_contents($filePath)));

            $attachmentsPart .= "--$this->boundaryMixed\r\n"
                . "Content-Type: $fileType; name*=\"UTF-8''" . $fileName . "\"\r\n"
                . "Content-Disposition: attachment; filename*=\"UTF-8''" . $fileName . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . "$fileContent\r\n\r\n";
        }
        return $attachmentsPart;
    }
}
