<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use Infocyph\TalkingBytes\Email\Enum\ContentTransferEncoding;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAttachment;

final readonly class AttachmentEncoder
{
    public function __construct(private FilenameEncoder $filenameEncoder = new FilenameEncoder()) {}

    public function encode(EmailAttachment $attachment): MimePart
    {
        $encodedContent = chunk_split(base64_encode($attachment->readContent()));
        $filename = $this->filenameEncoder->encode($attachment->name);

        $headers = [
            sprintf(
                'Content-Type: %s; name="%s"; name*=UTF-8\'\'%s',
                $attachment->mimeType,
                $filename['fallback'],
                $filename['star'],
            ),
            sprintf(
                'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
                $attachment->disposition,
                $filename['fallback'],
                $filename['star'],
            ),
            'Content-Transfer-Encoding: ' . ContentTransferEncoding::Base64->value,
        ];

        if ($attachment->isInline() && $attachment->contentId !== null && $attachment->contentId !== '') {
            $headers[] = sprintf('Content-ID: <%s>', trim($attachment->contentId, '<>'));
        }

        return new MimePart($headers, trim($encodedContent));
    }
}
