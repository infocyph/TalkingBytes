<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use DateTimeImmutable;
use Infocyph\TalkingBytes\Email\Parser\HeaderParser;

final class ImapEnvelopeParser
{
    /**
     * @return array{subject:?string,from:?string,date:?DateTimeImmutable,messageId:?string}|null
     */
    public function parse(ImapResponse $response): ?array
    {
        foreach ($response->lines as $line) {
            $envelope = $this->parseLine($line);
            if ($envelope !== null) {
                return $envelope;
            }
        }

        return null;
    }

    /**
     * @return array{subject:?string,from:?string,date:?DateTimeImmutable,messageId:?string}|null
     */
    private function parseLine(string $line): ?array
    {
        $start = stripos($line, 'ENVELOPE ');
        if ($start === false) {
            return null;
        }

        $start += strlen('ENVELOPE ');
        while (isset($line[$start]) && $line[$start] === ' ') {
            $start++;
        }

        if (!isset($line[$start]) || $line[$start] !== '(') {
            return null;
        }

        $envelopeChunk = $this->extractParenthesized($line, $start);
        if ($envelopeChunk === null) {
            return null;
        }

        $fields = $this->splitTopLevel(substr($envelopeChunk, 1, -1));
        if (count($fields) < 10) {
            return null;
        }

        $dateValue = $this->parseNullableString($fields[0]);
        $date = $dateValue === null ? null : new HeaderParser()->parseDate($dateValue);

        return [
            'subject' => $this->parseNullableString($fields[1]),
            'from' => $this->parseEnvelopeFromField($fields[2]),
            'date' => $date,
            'messageId' => $this->parseNullableString($fields[9]),
        ];
    }


    private function consumeParenthesis(
        string $char,
        int &$depth,
        string $value,
        int $start,
        int $index,
    ): ?string {
        if ($char === '(') {
            $depth++;

            return null;
        }

        if ($char !== ')') {
            return null;
        }

        $depth--;
        if ($depth === 0) {
            return substr($value, $start, $index - $start + 1);
        }

        return null;
    }

    private function consumeQuotedState(string $char, bool &$inQuote, bool &$escaped): bool
    {
        if (!$inQuote) {
            return false;
        }

        if ($escaped) {
            $escaped = false;

            return true;
        }

        if ($char === '\\') {
            $escaped = true;

            return true;
        }

        if ($char === '"') {
            $inQuote = false;
        }

        return true;
    }

    private function consumeQuotedStateWithBuffer(string $char, bool &$inQuote, bool &$escaped, string &$buffer): bool
    {
        if (!$inQuote) {
            return false;
        }

        $buffer .= $char;

        return $this->consumeQuotedState($char, $inQuote, $escaped);
    }

    private function extractParenthesized(string $value, int $start): ?string
    {
        $depth = 0;
        $inQuote = false;
        $escaped = false;
        $length = strlen($value);

        for ($index = $start; $index < $length; $index++) {
            $char = $value[$index];

            if ($this->consumeQuotedState($char, $inQuote, $escaped)) {
                continue;
            }

            if ($char === '"') {
                $inQuote = true;

                continue;
            }

            $chunk = $this->consumeParenthesis($char, $depth, $value, $start, $index);
            if ($chunk !== null) {
                return $chunk;
            }
        }

        return null;
    }

    /**
     * @param list<string> $parts
     */
    private function flushToken(array &$parts, string &$buffer): void
    {
        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $parts[] = $trimmed;
        }

        $buffer = '';
    }

    private function parseEnvelopeFromField(string $field): ?string
    {
        $trimmed = trim($field);
        if ($trimmed === '' || strtoupper($trimmed) === 'NIL' || $trimmed[0] !== '(') {
            return null;
        }

        $addressListRaw = substr($trimmed, 1, -1);
        $addresses = $this->splitTopLevel($addressListRaw);
        if ($addresses === []) {
            return null;
        }

        $first = trim($addresses[0]);
        if ($first === '' || $first[0] !== '(') {
            return null;
        }

        $tuple = $this->splitTopLevel(substr($first, 1, -1));
        if (count($tuple) < 4) {
            return null;
        }

        $name = $this->parseNullableString($tuple[0]);
        $mailbox = $this->parseNullableString($tuple[2]);
        $host = $this->parseNullableString($tuple[3]);
        if ($mailbox === null || $host === null) {
            return $name;
        }

        $email = sprintf('%s@%s', $mailbox, $host);
        if ($name === null || $name === '') {
            return $email;
        }

        return sprintf('%s <%s>', $name, $email);
    }

    private function parseNullableString(string $field): ?string
    {
        $trimmed = trim($field);
        if ($trimmed === '' || strtoupper($trimmed) === 'NIL') {
            return null;
        }

        if (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"')) {
            return stripcslashes(substr($trimmed, 1, -1));
        }

        return $trimmed;
    }

    /**
     * @return list<string>
     */
    private function appendStructuralChar(string $char, bool &$inQuote, int &$depth, string &$buffer): bool
    {
        if ($char === '"') {
            $inQuote = true;
        } elseif ($char === '(' || $char === ')') {
            $depth += $char === '(' ? 1 : -1;
        } else {
            return false;
        }

        $buffer .= $char;

        return true;
    }

    private function splitTopLevel(string $value): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $inQuote = false;
        $escaped = false;
        $index = 0;
        $length = strlen($value);

        while ($index < $length) {
            $char = $value[$index++];
            if ($this->consumeQuotedStateWithBuffer($char, $inQuote, $escaped, $buffer)) {
                continue;
            }

            if ($this->appendStructuralChar($char, $inQuote, $depth, $buffer)) {
                continue;
            }

            if ($depth === 0 && ctype_space($char)) {
                $this->flushToken($parts, $buffer);

                continue;
            }

            $buffer .= $char;
        }

        $this->flushToken($parts, $buffer);

        return $parts;
    }
}
