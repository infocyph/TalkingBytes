<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\ValueObject\EmailAddressList;
use Infocyph\TalkingBytes\Email\ValueObject\InboundEmailAddress;

final class AddressParser
{
    public function parse(?string $headerValue): EmailAddressList
    {
        if ($headerValue === null || trim($headerValue) === '') {
            return new EmailAddressList();
        }

        $imapAddresses = $this->parseWithImap($headerValue);
        if ($imapAddresses !== null) {
            $filtered = $this->filterValidAddresses($imapAddresses);
            if ($filtered !== []) {
                return new EmailAddressList($filtered);
            }
        }

        return new EmailAddressList($this->filterValidAddresses($this->parseWithoutImap($headerValue)));
    }

    private function fallbackAddress(string $raw): InboundEmailAddress
    {
        if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $raw, $match) === 1) {
            return new InboundEmailAddress($match[1], null, $raw);
        }

        return new InboundEmailAddress(null, null, $raw);
    }

    /**
     * @param list<InboundEmailAddress> $addresses
     * @return list<InboundEmailAddress>
     */
    private function filterValidAddresses(array $addresses): array
    {
        return array_values(array_filter(
            $addresses,
            static fn(InboundEmailAddress $address): bool => $address->email !== null,
        ));
    }

    private function parseMailbox(object $mailbox, string $rawHeader): InboundEmailAddress
    {
        $mailboxName = $this->readObjectString($mailbox, 'mailbox');
        $hostName = $this->readObjectString($mailbox, 'host');

        $email = null;
        if ($mailboxName !== null && $hostName !== null && $mailboxName !== '' && $hostName !== '' && $hostName !== '.SYNTAX-ERROR.') {
            $email = sprintf('%s@%s', $mailboxName, $hostName);
        }

        $personal = $this->readObjectString($mailbox, 'personal');
        $raw = $rawHeader;

        return new InboundEmailAddress($email, $personal, $raw);
    }

    /**
     * @return list<InboundEmailAddress>|null
     */
    private function parseWithImap(string $headerValue): ?array
    {
        if (!function_exists('imap_rfc822_parse_adrlist')) {
            return null;
        }

        $mailboxes = imap_rfc822_parse_adrlist($headerValue, '');
        if ($mailboxes === []) {
            return null;
        }

        $addresses = [];

        foreach ($mailboxes as $mailbox) {
            if (!is_object($mailbox)) {
                continue;
            }

            $addresses[] = $this->parseMailbox($mailbox, $headerValue);
        }

        if ($addresses === []) {
            return null;
        }

        return $addresses;
    }

    /**
     * @return list<InboundEmailAddress>
     */
    private function parseWithoutImap(string $headerValue): array
    {
        $normalized = $this->stripGroups($headerValue);
        $tokens = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $normalized) ?: [];
        $addresses = [];

        foreach ($tokens as $token) {
            $token = $this->stripComments(trim($token));
            if ($token === '') {
                continue;
            }

            if (preg_match('/^(?:"?([^"]*)"?\s*)?<([^>]+)>$/', $token, $matches) === 1) {
                $name = trim($matches[1]);
                $email = trim($matches[2]);
                $addresses[] = new InboundEmailAddress(
                    $this->validEmailOrNull($email),
                    $name !== '' ? trim($name, '"') : null,
                    $token,
                );

                continue;
            }

            $addresses[] = $this->fallbackAddress($token);
        }

        return $addresses;
    }

    private function readObjectString(object $mailbox, string $property): ?string
    {
        if (!property_exists($mailbox, $property)) {
            return null;
        }

        $value = $mailbox->{$property};

        return is_string($value) ? $value : null;
    }

    private function stripComments(string $value): string
    {
        return trim((string) preg_replace('/\s*\([^()]*\)\s*/', ' ', $value));
    }

    private function stripGroups(string $value): string
    {
        return (string) preg_replace('/[^:;,]+:\s*([^;]*);/m', '$1', $value);
    }

    private function validEmailOrNull(string $value): ?string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false ? $normalized : null;
    }
}
