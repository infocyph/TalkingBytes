<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\ValueObject\EmailAddressList;
use Infocyph\TalkingBytes\Email\ValueObject\HeaderBag;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmailPart;

final readonly class RawEmailParser implements EmailParser
{
    public function __construct(
        private HeaderParser $headerParser = new HeaderParser(),
        private AddressParser $addressParser = new AddressParser(),
        private MimeParser $mimeParser = new MimeParser(),
        private AttachmentExtractor $attachmentExtractor = new AttachmentExtractor(),
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public function parse(string $rawEmail, array $metadata = []): ParsedEmail
    {
        [$headerBlock, $body] = $this->splitRawMessage($rawEmail);
        $headers = $this->headerParser->parse($headerBlock);
        $rootPart = $this->mimeParser->parse($headers, $body);
        [$textBody, $htmlBody] = $this->collectBodies($rootPart);

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
            $this->attachmentExtractor->extract($rootPart),
            $this->flattenParts($rootPart),
            $headers->asMap(),
            $this->normalizeLineEndings($rawEmail),
            $metadata,
        );
    }

    private function addresses(HeaderBag $headers, string $name): EmailAddressList
    {
        return $this->addressParser->parse($headers->first($name));
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
            ++$index;

            $parts[] = $part;

            foreach ($part->children as $child) {
                $stack[] = $child;
                ++$stackCount;
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
