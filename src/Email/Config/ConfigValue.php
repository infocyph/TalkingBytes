<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

final class ConfigValue
{
    /**
     * @param array<string, mixed> $config
     */
    public static function bool(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }

            return $default;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function nullableInt(array $config, string $key): ?int
    {
        $value = self::nullableRaw($config, $key);
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function nullableString(array $config, string $key): ?string
    {
        $value = self::nullableRaw($config, $key);
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function string(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? $default;

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $default
     * @return list<string>
     */
    public static function stringList(array $config, string $key, array $default): array
    {
        $fallback = $default;
        $value = $config[$key] ?? $fallback;

        if (!is_array($value)) {
            return $fallback;
        }

        $list = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $list[] = $item;

                continue;
            }

            if (is_int($item) || is_float($item) || is_bool($item)) {
                $list[] = (string) $item;
            }
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function nullableRaw(array $config, string $key): mixed
    {
        if (!array_key_exists($key, $config)) {
            return null;
        }

        return $config[$key];
    }
}
