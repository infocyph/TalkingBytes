<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Support;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Throwable;

final class ObservabilitySanitizer
{
    /** @var list<string> */
    private const array SAFE_METADATA_KEYS = [
        'accepted_count',
        'attempts',
        'cancelled',
        'duration_ms',
        'partial_success',
        'rejected_count',
        'started',
        'transport',
    ];

    /** @return array<string, mixed> */
    public static function resultContext(CommunicationResult $result): array
    {
        $context = [
            'successful' => $result->successful,
            'status_code' => $result->statusCode,
        ];

        if (!$result->successful) {
            $context['failure_category'] = self::failureCategory($result);
        }

        foreach (self::SAFE_METADATA_KEYS as $key) {
            if (!array_key_exists($key, $result->metadata)) {
                continue;
            }

            $value = $result->metadata[$key];
            if (is_scalar($value) || $value === null) {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    /** @return array{failure_category:string,exception_class:class-string<Throwable>} */
    public static function throwableContext(Throwable $throwable): array
    {
        return [
            'failure_category' => 'exception',
            'exception_class' => $throwable::class,
        ];
    }

    private static function failureCategory(CommunicationResult $result): string
    {
        if (($result->metadata['cancelled'] ?? false) === true) {
            return 'cancelled';
        }
        if (($result->metadata['scheduler_error'] ?? false) === true) {
            return 'scheduler_error';
        }
        if ($result->statusCode !== null) {
            return 'protocol_status';
        }
        if (is_string($result->metadata['exception'] ?? null)) {
            return 'exception';
        }

        return 'transport_error';
    }
}
