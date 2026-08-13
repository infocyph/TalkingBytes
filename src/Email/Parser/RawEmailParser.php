<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\Config\EmailLimits;
use Infocyph\TalkingBytes\Email\Exception\EmailParseException;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAddressList;
use Infocyph\TalkingBytes\Email\ValueObject\HeaderBag;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmailPart;
use Infocyph\TalkingBytes\Email\ValueObject\ReceivedAttachment;

final readonly class RawEmailParser implements EmailParser
{
    private MimeParser $mimeParser;

    public function __construct(
        private HeaderParser $headerParser = new HeaderParser(),
        private AddressParser $addressParser = new AddressParser(),
        ?MimeParser $mimeParser = null,
        private AttachmentExtractor $attachmentExtractor = new AttachmentExtractor(),
        private EmailLimits $limits = new EmailLimits(),
    ) {
        $this->mimeParser = $mimeParser ?? new MimeParser(limits: $this->limits);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function parse(string $rawEmail, array $metadata = []): ParsedEmail
    {
        if (strlen($rawEmail) > $this->limits->maxMessageBytes) {
            throw new EmailParseException(sprintf(
                'Raw email exceeds max message size limit (%d bytes).',
                $this->limits->maxMessageBytes,
            ));
        }

        [$headerBlock, $body] = $this->splitRawMessage($rawEmail);
        $this->assertHeaderLimits($headerBlock);
        $headers = $this->headerParser->parse($headerBlock);
        $rootPart = $this->mimeParser->parse($headers, $body);
        $this->assertMimeLimits($rootPart);
        [$textBody, $htmlBody] = $this->collectBodies($rootPart);
        $attachments = $this->attachmentExtractor->extract($rootPart);
        $this->assertAttachmentLimits($attachments);
        $this->assertBodyLimits($rootPart);
        $parts = $this->flattenParts($rootPart);

        return new ParsedEmail(
            $this->addresses($headers, 'From'),
            $this->addresses($headers, 'To'),
            $this->addresses($headers, 'Cc'),
            $this->addresses($headers, 'Bcc'),
            $headers->first('Subject'),
            $this->headerParser->parseDate($headers->first('Date')),
            $this->normalizeMessageId($headers->first('Message-ID')),
            $this->normalizeMessageId($headers->first('In-Reply-To')),
            $this->headerParser->parseReferences($headers->first('References')),
            $textBody,
            $htmlBody,
            $attachments,
            $parts,
            $headers->asMap(),
            $rawEmail,
            $metadata,
        );
    }

    private function addresses(HeaderBag $headers, string $name): EmailAddressList
    {
        return $this->addressParser->parse($headers->first($name));
    }

    /**
     * @param list<ReceivedAttachment> $attachments
     */
    private function assertAttachmentLimits(array $attachments): void
    {
        if (count($attachments) > $this->limits->maxAttachmentCount) {
            throw new EmailParseException(sprintf(
                'Attachment count exceeds limit (%d).',
                $this->limits->maxAttachmentCount,
            ));
        }

        foreach ($attachments as $attachment) {
            if ($attachment->sizeBytes <= $this->limits->maxAttachmentBytes) {
                continue;
            }

            throw new EmailParseException(sprintf(
                'Attachment exceeds max size limit (%d bytes): %s',
                $this->limits->maxAttachmentBytes,
                $attachment->filename,
            ));
        }
    }

    private function assertBodyLimits(ParsedEmailPart $root): void
    {
        foreach ($this->flattenParts($root) as $part) {
            if ($part->children !== []) {
                continue;
            }

            if (strlen($part->body) > $this->limits->maxDecodedBodyBytes) {
                throw new EmailParseException(sprintf(
                    'Decoded MIME body exceeds limit (%d bytes).',
                    $this->limits->maxDecodedBodyBytes,
                ));
            }
        }
    }

    private function assertHeaderLimits(string $headerBlock): void
    {
        if (strlen($headerBlock) > $this->limits->maxHeaderBytes) {
            throw new EmailParseException(sprintf(
                'Header section exceeds limit (%d bytes).',
                $this->limits->maxHeaderBytes,
            ));
        }

        $headerLines = preg_split('/\r\n/', $headerBlock) ?: [];
        $fieldCount = 0;
        foreach ($headerLines as $line) {
            if (strlen($line) > $this->limits->maxHeaderLineBytes) {
                throw new EmailParseException(sprintf(
                    'Header line exceeds limit (%d bytes).',
                    $this->limits->maxHeaderLineBytes,
                ));
            }
            if ($line !== '' && !str_starts_with($line, ' ') && !str_starts_with($line, "\t")) {
                $fieldCount++;
            }
        }

        if ($fieldCount > $this->limits->maxHeaderCount) {
            throw new EmailParseException(sprintf(
                'Header count exceeds limit (%d).',
                $this->limits->maxHeaderCount,
            ));
        }
    }

    private function assertMimeLimits(ParsedEmailPart $root): void
    {
        $parts = 0;
        $maxDepth = 0;
        $partStack = [$root];
        $depthStack = [1];

        while ($partStack !== [] && $depthStack !== []) {
            $current = array_pop($partStack);
            $depth = array_pop($depthStack);

            $parts++;
            $maxDepth = max($maxDepth, $depth);

            if ($parts > $this->limits->maxMimeParts) {
                throw new EmailParseException(sprintf(
                    'MIME part count exceeds limit (%d).',
                    $this->limits->maxMimeParts,
                ));
            }

            if ($maxDepth > $this->limits->maxMimeDepth) {
                throw new EmailParseException(sprintf(
                    'MIME nesting depth exceeds limit (%d).',
                    $this->limits->maxMimeDepth,
                ));
            }

            foreach ($current->children as $child) {
                $partStack[] = $child;
                $depthStack[] = $depth + 1;
            }
        }
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function collectBodies(ParsedEmailPart $root): array
    {
        $textBody = null;
        $htmlBody = null;

        foreach ($this->flattenParts($root) as $part) {
            if ($part->children !== []) {
                continue;
            }

            if ($part->disposition === 'attachment') {
                continue;
            }

            if ($textBody === null && $part->contentType === 'text/plain') {
                $textBody = $part->body;

                continue;
            }

            if ($htmlBody === null && $part->contentType === 'text/html') {
                $htmlBody = $part->body;
            }
        }

        return [$textBody, $htmlBody];
    }

    /**
     * @return list<ParsedEmailPart>
     */
    private function flattenParts(ParsedEmailPart $root): array
    {
        $parts = [];
        $stack = [$root];
        $stackCount = count($stack);
        $index = 0;

        while ($index < $stackCount) {
            $part = $stack[$index];
            $index++;

            $parts[] = $part;

            foreach ($part->children as $child) {
                $stack[] = $child;
                $stackCount++;
            }
        }

        return $parts;
    }

    private function normalizeLineEndings(string $value): string
    {
        return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $value));
    }

    private function normalizeMessageId(?string $messageId): ?string
    {
        if ($messageId === null || trim($messageId) === '') {
            return null;
        }

        $trimmed = trim($messageId);

        if (str_starts_with($trimmed, '<') && str_ends_with($trimmed, '>')) {
            return $trimmed;
        }

        return sprintf('<%s>', trim($trimmed, '<>'));
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitRawMessage(string $raw): array
    {
        $normalized = $this->normalizeLineEndings($raw);
        $parts = preg_split("/\r\n\r\n/", $normalized, 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
