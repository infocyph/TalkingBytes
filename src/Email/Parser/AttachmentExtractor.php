<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmailPart;
use Infocyph\TalkingBytes\Email\ValueObject\ReceivedAttachment;

final class AttachmentExtractor
{
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

        $isAttachment = $part->disposition === 'attachment'
            || $part->filename !== null
            || ($part->contentId !== null && !str_starts_with($part->contentType, 'text/'));

        if (!$isAttachment) {
            return;
        }

        $filename = $part->filename ?? 'attachment.bin';
        $contents = $part->body;
        $attachments[] = new ReceivedAttachment(
            $filename,
            $part->contentType,
            strlen($contents),
            $part->contentId,
            $part->inline,
            static fn(): string => $contents,
        );
    }
}
