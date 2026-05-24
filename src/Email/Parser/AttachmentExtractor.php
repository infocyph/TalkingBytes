<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\ValueObject\AttachmentContentResolver;
use Infocyph\TalkingBytes\Email\ValueObject\InMemoryAttachmentContentResolver;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmailPart;
use Infocyph\TalkingBytes\Email\ValueObject\ReceivedAttachment;

final readonly class AttachmentExtractor
{
    /**
     * @param null|callable(ParsedEmailPart): AttachmentContentResolver $resolverFactory
     */
    public function __construct(private mixed $resolverFactory = null) {}

    /**
     * @return list<ReceivedAttachment>
     */
    public function extract(ParsedEmailPart $root): array
    {
        $attachments = [];
        $this->walk($root, $attachments);

        return $attachments;
    }

    /**
     * @param list<ReceivedAttachment> $attachments
     */
    private function walk(ParsedEmailPart $part, array &$attachments): void
    {
        if ($part->children !== []) {
            foreach ($part->children as $child) {
                $this->walk($child, $attachments);
            }

            return;
        }

        $hasFilename = $part->filename !== null && $part->filename !== '';
        $isInlineWithCid = $part->disposition === 'inline' && $part->contentId !== null;
        $isCidNonBodyText = $part->contentId !== null
            && !in_array($part->contentType, ['text/plain', 'text/html'], true);
        $isAttachment = $part->disposition === 'attachment'
            || $hasFilename
            || $isInlineWithCid
            || $isCidNonBodyText;

        if (!$isAttachment) {
            return;
        }

        $filename = $part->filename ?? 'attachment.bin';
        $resolver = $this->resolverFactory !== null
            ? ($this->resolverFactory)($part)
            : new InMemoryAttachmentContentResolver($part->body);

        $attachments[] = new ReceivedAttachment(
            $filename,
            $part->contentType,
            strlen($part->body),
            $part->contentId,
            $part->inline,
            $resolver,
        );
    }
}
