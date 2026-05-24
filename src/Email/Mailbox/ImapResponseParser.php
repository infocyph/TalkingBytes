<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Email\Parser\HeaderParser;

final readonly class ImapResponseParser
{
    public function __construct(
        private ImapEnvelopeParser $envelopeParser = new ImapEnvelopeParser(),
        private ImapListParser $listParser = new ImapListParser(),
        private ImapFetchLiteralMapper $literalMapper = new ImapFetchLiteralMapper(),
    ) {}

    /**
     * @return list<string>
     */
    public function capabilities(ImapResponse $response): array
    {
        $capabilities = [];

        foreach ($response->lines as $line) {
            if (preg_match('/^\*\s+CAPABILITY\s+(.+)$/i', $line, $matches) !== 1) {
                continue;
            }

            foreach (preg_split('/\s+/', trim($matches[1])) ?: [] as $capability) {
                $capabilities[] = strtoupper($capability);
            }
        }

        return array_values(array_unique($capabilities));
    }

    /**
     * @return array<string, list<string>>
     */
    public function fetchHeaderMap(ImapResponse $response): array
    {
        $headerRaw = $this->literalMapper->findBySections($response, ['HEADER', 'BODY.PEEK[HEADER]', 'BODY[HEADER]'])
            ?? $this->fetchRawMessage($response);
        if ($headerRaw === '') {
            return [];
        }

        $normalized = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $headerRaw));
        $headerBlock = explode("\r\n\r\n", $normalized, 2)[0];
        $lines = preg_split('/\r\n/', $headerBlock) ?: [];
        $headers = [];
        $currentName = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] === ' ' || $line[0] === "\t") && $currentName !== null) {
                $lastIndex = array_key_last($headers[$currentName]);
                $headers[$currentName][$lastIndex] .= ' ' . trim($line);

                continue;
            }

            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $currentName = strtolower(trim($name));
            $headers[$currentName] ??= [];
            $headers[$currentName][] = trim($value);
        }

        return $headers;
    }

    public function fetchRawMessage(ImapResponse $response): string
    {
        // Prefer explicit RFC822/BODY[] literals when present, then fall back to
        // the first FETCH-associated literal for simple one-literal responses.
        $rfc822 = $this->literalMapper->findBySections($response, ['RFC822', 'BODY[]', 'BODY.PEEK[]']);
        if ($rfc822 !== null) {
            return $rfc822;
        }

        return $this->literalMapper->firstFetchLiteral($response);
    }

    public function fetchSectionLiteral(ImapResponse $response, string $section): string
    {
        return $this->literalMapper->findBySections($response, [$section]) ?? '';
    }

    /**
     * @return list<MailboxFolderInfo>
     */
    public function folderDetails(ImapResponse $response): array
    {
        $folders = [];
        foreach ($response->lines as $line) {
            $parsed = $this->listParser->parse($line);
            if ($parsed === null) {
                continue;
            }

            $folders[] = $parsed;
        }

        return $folders;
    }

    /**
     * @return list<string>
     */
    public function folders(ImapResponse $response): array
    {
        $folders = [];
        foreach ($this->folderDetails($response) as $folder) {
            $folders[] = $folder->path;
        }

        return $folders;
    }

    public function parseBodyStructure(ImapResponse $response): string
    {
        foreach ($response->lines as $line) {
            if (preg_match('/BODYSTRUCTURE\s+(.+)$/i', $line, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return '';
    }

    public function parseEnvelopeSummary(ImapResponse $response, int $uid): MailboxMessageRef
    {
        $fetchMeta = $this->parseFetchMeta($response);

        $envelope = $this->envelopeParser->parse($response);
        if ($envelope !== null) {
            return new MailboxMessageRef(
                uid: $uid,
                subject: $envelope['subject'],
                date: $envelope['date'],
                from: $envelope['from'],
                flags: $fetchMeta['flags'],
                sizeBytes: strlen($this->fetchRawMessage($response)),
                messageId: $envelope['messageId'],
                sequence: $fetchMeta['sequence'],
            );
        }

        $headers = $this->fetchHeaderMap($response);
        $subject = $headers['subject'][0] ?? null;
        $from = $headers['from'][0] ?? null;
        $date = null;
        if (($headers['date'][0] ?? null) !== null) {
            $date = new HeaderParser()->parseDate($headers['date'][0]);
        }

        return new MailboxMessageRef(
            uid: $uid,
            subject: $subject,
            date: $date,
            from: $from,
            flags: $fetchMeta['flags'],
            sizeBytes: strlen($this->fetchRawMessage($response)),
            messageId: $headers['message-id'][0] ?? null,
            sequence: $fetchMeta['sequence'],
        );
    }

    /**
     * @return list<int>
     */
    public function searchUids(ImapResponse $response): array
    {
        return $this->parseUidList($response->lines, 'SEARCH');
    }

    /**
     * @return list<int>
     */
    public function sortUids(ImapResponse $response): array
    {
        return $this->parseUidList($response->lines, 'SORT');
    }

    public function status(ImapResponse $response): MailboxStatus
    {
        $values = [
            'MESSAGES' => 0,
            'RECENT' => 0,
            'UNSEEN' => 0,
            'UIDVALIDITY' => null,
            'UIDNEXT' => null,
        ];

        foreach ($response->lines as $line) {
            if (preg_match('/^\*\s+STATUS\s+.+\((.+)\)$/i', $line, $matches) !== 1) {
                continue;
            }

            $chunks = preg_split('/\s+/', trim($matches[1])) ?: [];
            for ($i = 0; $i < count($chunks) - 1; $i += 2) {
                $name = strtoupper($chunks[$i]);
                if (!array_key_exists($name, $values)) {
                    continue;
                }

                $values[$name] = ctype_digit($chunks[$i + 1]) ? (int) $chunks[$i + 1] : 0;
            }
        }

        return new MailboxStatus(
            messages: $values['MESSAGES'],
            recent: $values['RECENT'],
            unseen: $values['UNSEEN'],
            uidValidity: is_int($values['UIDVALIDITY']) ? $values['UIDVALIDITY'] : null,
            uidNext: is_int($values['UIDNEXT']) ? $values['UIDNEXT'] : null,
        );
    }

    /**
     * @return array{sequence:?int,flags:?list<string>}
     */
    private function parseFetchMeta(ImapResponse $response): array
    {
        foreach ($response->lines as $line) {
            if (preg_match('/^\*\s+(\d+)\s+FETCH\s+\((.+)\)$/i', $line, $matches) !== 1) {
                continue;
            }

            $sequence = ctype_digit($matches[1]) ? (int) $matches[1] : null;
            $flags = null;
            if (preg_match('/\bFLAGS\s+\(([^)]*)\)/i', $matches[2], $flagMatch) === 1) {
                $flagTokens = preg_split('/\s+/', trim($flagMatch[1])) ?: [];
                $flagTokens = array_values(array_filter($flagTokens, static fn(string $flag): bool => $flag !== ''));
                $flags = $flagTokens === [] ? [] : $flagTokens;
            }

            return ['sequence' => $sequence, 'flags' => $flags];
        }

        return ['sequence' => null, 'flags' => null];
    }

    /**
     * @return list<int>
     */
    private function parseUidChunks(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $uids = [];
        foreach (preg_split('/\s+/', $raw) ?: [] as $chunk) {
            if (ctype_digit($chunk)) {
                $uids[] = (int) $chunk;
            }
        }

        return $uids;
    }

    /**
     * @param list<string> $lines
     * @return list<int>
     */
    private function parseUidList(array $lines, string $token): array
    {
        foreach ($lines as $line) {
            if (preg_match('/^\*\s+' . preg_quote($token, '/') . '\s*(.*)$/i', $line, $matches) !== 1) {
                continue;
            }

            return $this->parseUidChunks(trim($matches[1]));
        }

        return [];
    }
}
