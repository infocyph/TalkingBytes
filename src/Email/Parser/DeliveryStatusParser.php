<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

final class DeliveryStatusParser
{
    /**
     * @return array{
     *   final_recipient:string|null,
     *   original_recipient:string|null,
     *   action:string|null,
     *   status:string|null,
     *   diagnostic_code:string|null,
     *   remote_mta:string|null,
     *   reporting_mta:string|null,
     *   last_attempt_date:string|null,
     *   will_retry_until:string|null
     * }
     */
    public function parse(string $body): array
    {
        $reports = $this->parseMany($body);
        if ($reports === []) {
            return [
                'final_recipient' => null,
                'original_recipient' => null,
                'action' => null,
                'status' => null,
                'diagnostic_code' => null,
                'remote_mta' => null,
                'reporting_mta' => null,
                'last_attempt_date' => null,
                'will_retry_until' => null,
            ];
        }

        return $reports[0];
    }

    /**
     * @return list<array{
     *   final_recipient:string|null,
     *   original_recipient:string|null,
     *   action:string|null,
     *   status:string|null,
     *   diagnostic_code:string|null,
     *   remote_mta:string|null,
     *   reporting_mta:string|null,
     *   last_attempt_date:string|null,
     *   will_retry_until:string|null
     * }>
     */
    public function parseMany(string $body): array
    {
        $normalized = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $body));
        $blocks = preg_split("/\r\n\r\n+/", trim($normalized)) ?: [];

        $globalFields = [];
        $recipientFields = [];

        foreach ($blocks as $block) {
            $fields = $this->parseFieldBlock($block);
            if ($fields === []) {
                continue;
            }

            if (array_key_exists('final-recipient', $fields)) {
                $recipientFields[] = $fields;

                continue;
            }

            $globalFields = array_merge($globalFields, $fields);
        }

        if ($recipientFields === []) {
            return [[
                'final_recipient' => null,
                'original_recipient' => null,
                'action' => null,
                'status' => null,
                'diagnostic_code' => null,
                'remote_mta' => null,
                'reporting_mta' => $this->normalizeMta($globalFields['reporting-mta'] ?? null),
                'last_attempt_date' => null,
                'will_retry_until' => null,
            ]];
        }

        $reports = [];
        foreach ($recipientFields as $recipient) {
            $reports[] = [
                'final_recipient' => $this->normalizeRecipient($recipient['final-recipient']),
                'original_recipient' => $this->normalizeRecipient($recipient['original-recipient'] ?? null),
                'action' => $this->nullableString($recipient['action'] ?? null),
                'status' => $this->nullableString($recipient['status'] ?? null),
                'diagnostic_code' => $this->nullableString($recipient['diagnostic-code'] ?? null),
                'remote_mta' => $this->normalizeMta($recipient['remote-mta'] ?? null),
                'reporting_mta' => $this->normalizeMta($globalFields['reporting-mta'] ?? null),
                'last_attempt_date' => $this->nullableString($recipient['last-attempt-date'] ?? null),
                'will_retry_until' => $this->nullableString($recipient['will-retry-until'] ?? null),
            ];
        }

        return $reports;
    }

    private function normalizeMta(?string $value): ?string
    {
        return $this->normalizeSemicolonValue($value);
    }

    private function normalizeRecipient(?string $value): ?string
    {
        return $this->normalizeSemicolonValue($value);
    }

    private function normalizeSemicolonValue(?string $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        if (!str_contains($value, ';')) {
            return $value;
        }

        [, $value] = array_pad(explode(';', $value, 2), 2, '');

        return $this->nullableString($value);
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, string>
     */
    private function parseFieldBlock(string $block): array
    {
        $fields = [];
        $currentKey = null;

        $lines = preg_split('/\r\n/', $block) ?: [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                if ($currentKey !== null && array_key_exists($currentKey, $fields)) {
                    $fields[$currentKey] .= ' ' . trim($line);
                }

                continue;
            }

            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $currentKey = strtolower(trim($name));
            $fields[$currentKey] = trim($value);
        }

        return $fields;
    }
}
