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
            return new EmailAddressList($imapAddresses);
        }

        return new EmailAddressList($this->parseWithoutImap($headerValue));
    }

    private function fallbackAddress(string $raw): InboundEmailAddress
    {
        if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $raw, $match) === 1) {
            return new InboundEmailAddress($match[1], null, $raw);
        }

        return new InboundEmailAddress(null, null, $raw);
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
        $raw = $this->readObjectString($mailbox, 'adl') ?? $rawHeader;

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
        $tokens = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $headerValue) ?: [];
        $addresses = [];

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (preg_match('/^(?:"?([^"]*)"?\s*)?<([^>]+)>$/', $token, $matches) === 1) {
                $name = trim($matches[1]);
                $email = trim($matches[2]);
                $addresses[] = new InboundEmailAddress($email !== '' ? $email : null, $name !== '' ? $name : null, $token);

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
}
