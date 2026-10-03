<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\Config\EmailLimits;
use Infocyph\TalkingBytes\Email\Exception\EmailParseException;

final class HeaderLimitValidator
{
    public static function assertWithin(string $headerBlock, EmailLimits $limits): void
    {
        if (strlen($headerBlock) > $limits->maxHeaderBytes) {
            throw new EmailParseException(sprintf(
                'Header section exceeds limit (%d bytes).',
                $limits->maxHeaderBytes,
            ));
        }

        $fieldCount = 0;
        foreach (preg_split('/\r\n/', $headerBlock) ?: [] as $line) {
            if (strlen($line) > $limits->maxHeaderLineBytes) {
                throw new EmailParseException(sprintf(
                    'Header line exceeds limit (%d bytes).',
                    $limits->maxHeaderLineBytes,
                ));
            }

            if ($line !== '' && !str_starts_with($line, ' ') && !str_starts_with($line, "\t")) {
                $fieldCount++;
            }
        }

        if ($fieldCount > $limits->maxHeaderCount) {
            throw new EmailParseException(sprintf(
                'Header count exceeds limit (%d).',
                $limits->maxHeaderCount,
            ));
        }
    }
}
