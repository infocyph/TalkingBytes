<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use InvalidArgumentException;

final class IdnConverter
{
    public function toAsciiDomain(string $domain): string
    {
        $trimmed = trim($domain);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Email domain must not be empty.');
        }

        if ($this->isAscii($trimmed)) {
            return strtolower($trimmed);
        }

        if (!function_exists('idn_to_ascii')) {
            throw new InvalidArgumentException('IDN conversion requires the intl extension.');
        }

        $converted = idn_to_ascii($trimmed, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (!is_string($converted) || $converted === '') {
            throw new InvalidArgumentException(sprintf('Unable to convert IDN domain to ASCII: %s', $domain));
        }

        return strtolower($converted);
    }

    private function isAscii(string $value): bool
    {
        return preg_match('/^[\x00-\x7F]+$/', $value) === 1;
    }
}
