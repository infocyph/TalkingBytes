<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use Infocyph\TalkingBytes\Email\ValueObject\EmailAddress;

final class AddressFormatter
{
    public function encodeMimeHeader(string $value): string
    {
        if ($this->isAscii($value)) {
            return $value;
        }

        return sprintf('=?UTF-8?B?%s?=', base64_encode($value));
    }

    public function format(EmailAddress $address): string
    {
        if ($address->name === null || $address->name === '') {
            return $address->email;
        }

        return sprintf('%s <%s>', $this->encodeDisplayName($address->name), $address->email);
    }

    /**
     * @param list<EmailAddress> $addresses
     */
    public function formatList(array $addresses): string
    {
        return implode(', ', array_map($this->format(...), $addresses));
    }

    private function encodeDisplayName(string $value): string
    {
        $encoded = $this->encodeMimeHeader($value);

        if ($encoded !== $value) {
            return $encoded;
        }

        if (preg_match('/[,;"]/', $value) === 1) {
            return sprintf('"%s"', addcslashes($value, '"\\'));
        }

        return $value;
    }

    private function isAscii(string $value): bool
    {
        return preg_match('/^[\x20-\x7E]*$/', $value) === 1;
    }
}
