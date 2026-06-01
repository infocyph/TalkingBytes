<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Support;

final class WebhookHeaders
{
    public const string ATTEMPT = 'X-TB-Attempt';

    public const string CONTENT_TYPE = 'Content-Type';

    public const string DELIVERY = 'X-TB-Delivery';

    public const string EVENT = 'X-TB-Event';

    public const string SIGNATURE = 'X-TB-Signature';

    public const string TIMESTAMP = 'X-TB-Timestamp';

    public const string USER_AGENT = 'User-Agent';

    public static function isReserved(string $name): bool
    {
        $normalized = strtolower($name);

        return array_any(self::reserved(), fn($reserved) => strtolower((string) $reserved) === $normalized);
    }

    /**
     * @return list<string>
     */
    public static function reserved(): array
    {
        return [
            self::EVENT,
            self::DELIVERY,
            self::TIMESTAMP,
            self::SIGNATURE,
            self::ATTEMPT,
            self::CONTENT_TYPE,
            self::USER_AGENT,
        ];
    }
}
