<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

final class ImapModifiedUtf7
{
    public static function decode(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF7-IMAP');
        }

        if (function_exists('imap_utf7_decode')) {
            $decoded = imap_utf7_decode($value);

            return $decoded === false ? $value : $decoded;
        }

        return $value;
    }

    public static function encode(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF7-IMAP', 'UTF-8');
        }

        if (function_exists('imap_utf7_encode')) {
            return imap_utf7_encode($value);
        }

        return $value;
    }
}
