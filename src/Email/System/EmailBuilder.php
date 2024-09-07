<?php

namespace Infocyph\TakingBytes\Email\System;

class EmailBuilder
{
    private $boundaryAlternative;
    private $boundaryMixed = null;

    public function __construct()
    {
        $this->boundaryAlternative = sha1(uniqid(time(), true));
    }

    public function buildHeaders($from, $cc = [], $bcc = [], $replyTo = '', $attachments = [])
    {
        return $this->formatFromHeader($from)
            . $this->formatReplyToHeader($replyTo)
            . $this->formatCcBccHeaders($cc, $bcc)
            . $this->formatMimeHeader($attachments);
    }

    private function formatFromHeader($from)
    {
        return "From: =?UTF-8?B?" . base64_encode($from['name']) . "?= <{$from['email']}>\r\n";
    }

    private function formatReplyToHeader($replyTo)
    {
        return "Reply-To: {$replyTo}\r\n";
    }

    private function formatCcBccHeaders($cc, $bcc)
    {
        $headers = "";
        if (!empty($cc)) {
            $headers .= "Cc: " . implode(',', $cc) . "\r\n";
        }
        if (!empty($bcc)) {
            $headers .= "Bcc: " . implode(',', $bcc) . "\r\n";
        }
        return $headers;
    }

    private function formatMimeHeader($attachments)
    {
        if (!empty($attachments)) {
            $this->boundaryMixed = sha1(uniqid(time(), true));
            return "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$this->boundaryMixed}\"\r\n";
        } else {
            return "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$this->boundaryAlternative}\"\r\n";
        }
    }

    public function buildBody($plainText, $htmlContent = '', $attachments = [])
    {
        if (empty($plainText) && !empty($htmlContent)) {
            $plainText = strip_tags($htmlContent);
        }

        $message = $this->buildAlternativeBody($plainText, $htmlContent);
        if (!empty($attachments)) {
            $message = $this->wrapWithMixedBoundary($message, $attachments);
        }

        return $message;
    }

    private function buildAlternativeBody($plainText, $htmlContent)
    {
        $message = "";
        $message .= $this->buildPlainTextPart($plainText);
        if (!empty($htmlContent)) {
            $message .= $this->buildHtmlPart($htmlContent);
        }
        $message .= "--{$this->boundaryAlternative}--\r\n";
        return $message;
    }

    private function wrapWithMixedBoundary($message, $attachments)
    {
        $wrappedMessage = "--{$this->boundaryMixed}\r\n";
        $wrappedMessage .= "Content-Type: multipart/alternative; boundary=\"{$this->boundaryAlternative}\"\r\n\r\n";
        $wrappedMessage .= $message;
        $wrappedMessage .= $this->buildAttachmentsPart($attachments);
        $wrappedMessage .= "--{$this->boundaryMixed}--\r\n";
        return $wrappedMessage;
    }

    private function buildPlainTextPart($plainText)
    {
        return "--{$this->boundaryAlternative}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 7bit\r\n\r\n"
            . "{$plainText}\r\n\r\n";
    }

    private function buildHtmlPart($htmlContent)
    {
        return "--{$this->boundaryAlternative}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . $this->encodeQuotedPrintable($htmlContent) . "\r\n\r\n";
    }

    private function buildAttachmentsPart($attachments)
    {
        $attachmentsPart = "";
        foreach ($attachments as $attachment) {
            $filePath = $attachment['path'];
            $fileName = $attachment['name'];
            $fileType = mime_content_type($filePath);
            $fileContent = chunk_split(base64_encode(file_get_contents($filePath)));

            $attachmentsPart .= "--{$this->boundaryMixed}\r\n"
                . "Content-Type: $fileType; name*=\"UTF-8''" . rawurlencode($fileName) . "\"\r\n"
                . "Content-Disposition: attachment; filename*=\"UTF-8''" . rawurlencode($fileName) . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . "$fileContent\r\n\r\n";
        }
        return $attachmentsPart;
    }

    private function encodeQuotedPrintable($string)
    {
        return quoted_printable_encode($string);
    }
}
