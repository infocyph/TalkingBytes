<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

final class ImapStringEscaper
{
    public static function quote(string $value): string
    {
        $encoded = ImapModifiedUtf7::encode($value);

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $encoded) . '"';
    }

    public static function unquote(string $value): string
    {
        $trimmed = trim($value);
        if (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"')) {
            $trimmed = substr($trimmed, 1, -1);
        }

        $unescaped = stripcslashes($trimmed);

        return ImapModifiedUtf7::decode($unescaped);
    }
}
