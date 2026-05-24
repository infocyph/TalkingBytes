<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

final class ImapListParser
{
    public function parse(string $line): ?MailboxFolderInfo
    {
        if (preg_match('/^\*\s+LIST\s+\(([^)]*)\)\s+("([^"\\\\]|\\\\.)*"|NIL)\s+(.+)$/i', $line, $matches) !== 1) {
            return null;
        }

        $attributes = $this->parseAttributes($matches[1]);
        $delimiterValue = $this->decodeFolderValue($matches[2]);
        $rawName = $this->decodeFolderValue($matches[4]);
        $decodedName = ImapModifiedUtf7::decode($rawName);

        return new MailboxFolderInfo(
            name: $decodedName,
            path: $decodedName,
            delimiter: $delimiterValue === '' ? null : $delimiterValue,
            attributes: $attributes,
        );
    }

    private function decodeFolderValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === 'NIL') {
            return '';
        }

        if (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"')) {
            return stripcslashes(substr($trimmed, 1, -1));
        }

        return $trimmed;
    }

    /**
     * @return list<string>
     */
    private function parseAttributes(string $raw): array
    {
        $attributeChunks = preg_split('/\s+/', trim($raw)) ?: [];
        $attributes = [];
        foreach ($attributeChunks as $attribute) {
            $upper = strtoupper(trim($attribute));
            if ($upper === '') {
                continue;
            }

            $attributes[] = $upper;
        }

        return $attributes;
    }
}
