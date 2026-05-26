<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

final readonly class DnsDkimPublicKeyResolver implements DkimPublicKeyResolver
{
    /**
     * @param null|callable(string, int): mixed $dnsLookup
     */
    public function __construct(private mixed $dnsLookup = null) {}

    public function resolve(string $domain, string $selector): ?string
    {
        $recordName = sprintf('%s._domainkey.%s', trim($selector), trim($domain));
        $lookup = $this->dnsLookup;
        $records = is_callable($lookup)
            ? $lookup($recordName, DNS_TXT)
            : dns_get_record($recordName, DNS_TXT);

        if (!is_array($records) || $records === []) {
            return null;
        }

        $dkimRecords = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $txt = $this->extractTxtRecord($record);
            if ($txt === null || trim($txt) === '') {
                continue;
            }

            if (!str_contains(strtolower($txt), 'v=dkim1')) {
                continue;
            }

            $dkimRecords[] = $txt;
        }

        foreach ($dkimRecords as $dkimRecord) {
            $publicKey = $this->extractTag($dkimRecord, 'p');
            if ($publicKey === null || trim($publicKey) === '') {
                continue;
            }

            return $dkimRecord;
        }

        return null;
    }

    private function extractTag(string $record, string $tag): ?string
    {
        $parts = preg_split('/\s*;\s*/', trim($record)) ?: [];
        $needle = strtolower($tag) . '=';

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (!str_starts_with(strtolower($part), $needle)) {
                continue;
            }

            return trim(substr($part, strlen($needle)));
        }

        return null;
    }

    /**
     * @param array<mixed, mixed> $record
     */
    private function extractTxtRecord(array $record): ?string
    {
        $entries = $record['entries'] ?? null;
        if (is_array($entries) && $entries !== []) {
            $chunks = [];
            foreach ($entries as $entry) {
                if (!is_string($entry)) {
                    continue;
                }

                $chunks[] = $entry;
            }

            if ($chunks !== []) {
                return implode('', $chunks);
            }
        }

        $txt = $record['txt'] ?? null;
        if (!is_string($txt) || $txt === '') {
            return null;
        }

        return $txt;
    }
}
