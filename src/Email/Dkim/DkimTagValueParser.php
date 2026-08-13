<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

final readonly class DkimTagValueParser
{
    /** @return array<string, string> */
    public static function parse(string $value): array
    {
        $parts = preg_split('/\s*;\s*/', trim($value)) ?: [];
        if (count($parts) > 32) {
            return [];
        }

        $tags = [];
        foreach ($parts as $part) {
            if ($part === '' || !str_contains($part, '=')) {
                continue;
            }

            [$name, $tagValue] = explode('=', $part, 2);
            $name = strtolower(trim($name));
            if ($name === '' || isset($tags[$name])) {
                return [];
            }

            $tags[$name] = trim($tagValue);
        }

        return $tags;
    }
}
