<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use Infocyph\TalkingBytes\Email\Exception\InvalidHeaderValueException;

final class HeaderValueGuard
{
    public static function assertHeaderName(string $name): void
    {
        if ($name === '' || preg_match('/^[!#$%&\'\*+\-.\^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new InvalidHeaderValueException(sprintf('Invalid header name: %s', $name));
        }
    }

    public static function assertNoCrlf(string $value, string $headerName = 'header'): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidHeaderValueException(sprintf('%s value must not contain CRLF.', $headerName));
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidHeaderValueException(sprintf('%s value contains invalid control characters.', $headerName));
        }
    }
}
