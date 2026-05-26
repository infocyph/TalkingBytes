<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

final readonly class ImapFetchLiteralMapper
{
    /**
     * @param list<string> $sections
     */
    public function findBySections(ImapResponse $response, array $sections): ?string
    {
        $normalizedSections = array_map(
            static fn(string $section): string => strtoupper(trim($section)),
            $sections,
        );

        $cursor = 0;
        foreach ($response->lines as $line) {
            $cursor = $this->scanLineForSectionLiterals(
                line: $line,
                response: $response,
                cursor: $cursor,
                sections: $normalizedSections,
                literal: $literal,
            );

            if ($literal !== null) {
                return $literal;
            }
        }

        return null;
    }

    public function firstFetchLiteral(ImapResponse $response): string
    {
        $cursor = 0;
        foreach ($response->lines as $line) {
            $literalCount = preg_match_all('/\{(\d+)\}/', $line);
            $hasLiteral = is_int($literalCount) && $literalCount > 0;

            if (preg_match('/^\*\s+\d+\s+FETCH\s+\(/i', $line) !== 1) {
                if ($hasLiteral) {
                    $cursor += $literalCount;
                }

                continue;
            }

            if ($hasLiteral && array_key_exists($cursor, $response->literals)) {
                return $response->literals[$cursor];
            }

            $cursor += $hasLiteral ? $literalCount : 0;
        }

        return $response->literals !== [] ? $response->literals[0] : '';
    }

    /**
     * @param list<string> $sections
     */
    private function lineContainsAnySection(string $token, array $sections): bool
    {
        if ($token === '') {
            return false;
        }

        return array_any($sections, fn($section): bool => str_contains($token, (string) $section));
    }

    /**
     * @param list<string> $sections
     */
    private function scanLineForSectionLiterals(
        string $line,
        ImapResponse $response,
        int $cursor,
        array $sections,
        ?string &$literal,
    ): int {
        $matches = [];
        preg_match_all('/\{(\d+)\}/', $line, $matches, PREG_OFFSET_CAPTURE);
        $literalMatchCount = count($matches[0]);
        if ($literalMatchCount === 0) {
            return $cursor;
        }

        for ($index = 0; $index < $literalMatchCount; $index++) {
            $offset = $matches[0][$index][1] ?? null;
            if (!is_int($offset)) {
                $cursor++;

                continue;
            }

            $token = strtoupper(trim(substr($line, 0, $offset)));
            if ($this->lineContainsAnySection($token, $sections) && array_key_exists($cursor, $response->literals)) {
                $literal = $response->literals[$cursor];

                return $cursor + 1;
            }

            $cursor++;
        }

        return $cursor;
    }
}
