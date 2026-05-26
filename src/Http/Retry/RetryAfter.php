<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Retry;

use DateTimeImmutable;
use DateTimeInterface;

final class RetryAfter
{
    public static function parseDelaySeconds(string $value, ?DateTimeInterface $now = null): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (ctype_digit($trimmed)) {
            return max(0, (int) $trimmed);
        }

        try {
            $retryAt = new DateTimeImmutable($trimmed);
        } catch (\Exception) {
            return null;
        }

        $current = $now ?? new DateTimeImmutable();
        $seconds = $retryAt->getTimestamp() - $current->getTimestamp();

        return max(0, $seconds);
    }
}
